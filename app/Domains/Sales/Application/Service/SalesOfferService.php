<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOfferRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Support\SalesOfferSnapshot;
use Domains\Sales\Application\UseCase\SetDealCommercialTerms;
use Domains\Sales\Domain\Policy\SalesOfferPolicy;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class SalesOfferService
{
    public function __construct(private SalesOfferRepositoryInterface $offers,private SalesOfferReferencePort $references,
        private DealStageRepositoryInterface $deals,private SetDealCommercialTerms $commercial,
        private SalesMutationReceiptRepositoryInterface $receipts,private EventBus $events,
        private TransactionManagerInterface $transactions){}

    public function execute(string $org,int $actor,string $operation,array $input,string $key,string $correlation):array
    {
        $deal=$input['deal_id']??null;$key=trim($key);
        if($org===''||$actor<1||!is_int($deal)||$deal<1||$key===''||strlen($key)>191||$correlation===''||strlen($correlation)>191)throw new DomainException('Invalid offer command identity.');
        $this->validate($operation,$input);
        $terms=in_array($operation,['create','revise'],true)?SalesOfferPolicy::terms($input):null;
        $fingerprint=SalesOfferSnapshot::digest(['actor'=>$actor,'operation'=>$operation,'input'=>$input]);
        $namespace='offer.'.$operation;
        return $this->transactions->transactional(function()use($org,$actor,$operation,$input,$deal,$key,$correlation,$terms,$fingerprint,$namespace):array{
            $native=$this->deals->getForStageChange($org,(string)$deal);
            if($native===null||($native['organization_id']??null)!==$org||(int)$native['id']!==$deal)throw new DomainException('Tenant-owned deal not found.');
            $pending='pending:'.bin2hex(random_bytes(16));
            if(!$this->receipts->claim($org,$namespace,$key,$pending)){
                $receipt=$this->receipts->find($org,$namespace,$key);
                if($receipt===null||preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$receipt,$parts)!==1||!hash_equals($fingerprint,$parts[1]))throw new DomainException('Offer receipt conflict.');
                $state=$this->read($org,$parts[2],$deal);
                $applied=$operation==='create'?1:$input['expected_revision']+1;
                if($state['revision']<$applied)throw new RuntimeException('Offer receipt has no native revision.');
                return ['offer'=>$state,'applied_revision'=>$applied,'replayed'=>true];
            }
            if(in_array($native['status'],['closed','lost'],true))throw new DomainException('Terminal deal cannot change offers.');
            $accepted=$this->offers->acceptedForDeal($org,$deal);
            if($accepted!==null&&!($operation==='transition'&&$input['offer_id']!==$accepted['offer_id']&&in_array($input['target_status'],['withdrawn','expired','rejected'],true)))throw new DomainException('An accepted offer already fixes commercial terms for this deal.');
            $before=null;$kind=$operation;$from=null;$to='draft';$version=1;$revision=1;
            $evidence=$input['evidence_reference']??null;$note=$input['note']??null;
            if($operation==='create'){
                $id=bin2hex(random_bytes(16));
                $terms=$this->resolveTerms($org,$terms);
                $this->offers->create($org,$id,$deal,$actor);
                $this->offers->appendTerms($org,$id,1,$terms,'sales',$actor);
            }else{
                $id=$input['offer_id'];$before=$this->read($org,$id,$deal);
                if($before['revision']!==$input['expected_revision'])throw new DomainException('Offer revision conflict.');
                $revision=$before['revision']+1;$version=$before['terms_version'];$from=$before['status'];$to=$from;
                if(!in_array($from,['draft','presented'],true))throw new DomainException('Terminal offer is immutable.');
                if($operation==='revise'){
                    if($input['direction']==='customer'&&$from!=='presented')throw new DomainException('Customer counteroffer requires a presented quote.');
                    if($input['direction']==='customer'&&$evidence===null)throw new DomainException('Customer counteroffer evidence required.');
                    $terms=$this->resolveTerms($org,$terms);
                    $version++;$to='draft';$kind=$input['direction']==='customer'?'counteroffer':'revise';
                    $this->offers->appendTerms($org,$id,$version,$terms,$input['direction'],$actor);
                }elseif($operation==='transition'){
                    $to=$input['target_status'];
                    if(!SalesOfferPolicy::transition($from,$to))throw new DomainException('Prohibited offer transition.');
                    $expired=$before['valid_until']<=gmdate('Y-m-d H:i:s');
                    if($to==='expired'&&!$expired)throw new DomainException('Offer is not expired.');
                    if(in_array($to,['presented','accepted'],true)&&$expired)throw new DomainException('Offer terms expired; revise before proceeding.');
                    if(in_array($to,['presented','accepted','rejected'],true)&&$evidence===null)throw new DomainException('Operator-recorded customer interaction reference required.');
                    if(in_array($to,['presented','accepted'],true)){
                        if($before['direction']!=='sales')throw new DomainException('Customer counteroffer requires a sales-issued revision.');
                        $this->verifyReferences($org,$before['lines']);
                    }
                    if($to==='accepted'){
                        if($before['direction']==='customer')throw new DomainException('Customer counteroffer must be adopted into a sales-issued revision before acceptance.');
                        $result=$this->commercial->execute($org,(string)$deal,$before['total'],$before['currency'],(string)$actor,$correlation);
                        if(!$result->successful)throw new DomainException($result->error??'Deal commercial reconciliation rejected.');
                    }
                    $kind=$to;
                }else{
                    $kind=$input['kind'];
                }
                $this->offers->advance($org,$id,$before['revision'],$version,$to);
            }
            $this->offers->appendHistory($org,$id,$revision,$version,$kind,$from,$to,$evidence,$note,$actor,$correlation);
            $state=$this->read($org,$id,$deal);
            if($state['revision']!==$revision||$state['terms_version']!==$version||$state['status']!==$to)throw new RuntimeException('Offer native readback failed.');
            if($terms!==null&&$state['terms_hash']!==SalesOfferSnapshot::digest($terms))throw new RuntimeException('Offer immutable terms differ.');
            if($to==='accepted'){
                $actual=$this->deals->getForStageChange($org,(string)$deal);
                if($actual===null||(string)$actual['deal_value']!==$state['total']||$actual['currency']!==$state['currency'])throw new RuntimeException('Accepted offer and Deal terms differ.');
            }
            $type=match($operation){'create'=>'sales.offer.created','revise'=>'sales.offer.revised','transition'=>'sales.offer.state_changed',default=>'sales.negotiation.recorded'};
            $this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$org,$type,'offer',$id,
                ['deal_id'=>$deal,'revision'=>$revision,'terms_version'=>$version,'status'=>$to,'kind'=>$kind,
                    'total'=>$state['total'],'currency'=>$state['currency'],'terms_hash'=>$state['terms_hash'],'evidence_kind'=>'operator_recorded'],
                new EventMetadata($correlation,null,'USER',(string)$actor),new DateTimeImmutable()));
            if(!$this->receipts->complete($org,$namespace,$key,$pending,$fingerprint.':'.$id))throw new RuntimeException('Offer receipt completion failed.');
            return ['offer'=>$state,'applied_revision'=>$revision,'replayed'=>false];
        });
    }

    public function read(string $org,string $id,?int $deal=null):array
    {
        $row=$this->offers->get($org,$id);
        if($row===null||($deal!==null&&(int)$row['deal_id']!==$deal))throw new DomainException('Tenant-owned offer not found.');
        return SalesOfferSnapshot::fromNative($row,$org,$id);
    }

    private function resolveTerms(string $org,array $terms):array
    {
        if($terms['valid_until']<=gmdate('Y-m-d H:i:s'))throw new DomainException('Offer validity must be in the future.');
        foreach($terms['lines'] as &$line){
            $reference=$this->references->resolve($org,$line);
            if($reference===null)throw new DomainException('Offer source reference unavailable.');
            $line['reference_snapshot']=$reference;
        }
        unset($line);return $terms;
    }

    private function verifyReferences(string $org,array $lines):void
    {
        foreach($lines as $line){
            $fresh=$this->references->resolve($org,$line);
            if($fresh===null||SalesOfferSnapshot::digest($fresh)!==SalesOfferSnapshot::digest($line['reference_snapshot']))throw new DomainException('Referenced catalog/inventory changed; revise the quote.');
        }
    }

    private function validate(string $op,array $in):void
    {
        $terms=['title','currency','valid_until','lines'];$identity=['deal_id','offer_id','expected_revision'];
        $required=match($op){
            'create'=>['deal_id',...$terms],
            'revise'=>[...$identity,...$terms,'direction'],
            'transition'=>[...$identity,'target_status'],
            'negotiation'=>[...$identity,'kind','note'],
            default=>throw new DomainException('Unknown offer operation.'),
        };
        $optional=$op==='create'?[]:($op==='negotiation'?['evidence_reference']:['evidence_reference','note']);
        if(array_diff($required,array_keys($in))!==[]||array_diff(array_keys($in),[...$required,...$optional])!==[])throw new DomainException('Unexpected offer fields.');
        if($op!=='create'&&(!is_string($in['offer_id'])||preg_match('/^[a-f0-9]{32}$/D',$in['offer_id'])!==1||!is_int($in['expected_revision'])||$in['expected_revision']<1))throw new DomainException('Invalid offer identity or revision.');
        if($op==='revise'&&!in_array($in['direction'],['sales','customer'],true))throw new DomainException('Invalid counteroffer direction.');
        if($op==='transition'&&!in_array($in['target_status'],['presented','accepted','rejected','withdrawn','expired'],true))throw new DomainException('Unknown offer state.');
        if($op==='negotiation'&&!in_array($in['kind'],['objection','clarification','meeting','customer_response','internal_note'],true))throw new DomainException('Unknown negotiation kind.');
        foreach(['evidence_reference'=>191,'note'=>2000] as $field=>$max)if(array_key_exists($field,$in)&&(!is_string($in[$field])||trim($in[$field])!==$in[$field]||$in[$field]===''||mb_strlen($in[$field])>$max))throw new DomainException('Invalid negotiation evidence.');
    }
}
