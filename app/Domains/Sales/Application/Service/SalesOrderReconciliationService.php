<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DomainException;
use RuntimeException;
use Domains\ProductManagement\Application\Contract\CommerceOrderSourcePort;
use Domains\Sales\Application\Contract\SalesOrderSourceRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\Sales\Domain\Policy\SalesOrderTerms;

/** Supervised internal reconciliation: provider read only; Sales owns canonical placement. */
final readonly class SalesOrderReconciliationService
{
    private const PERMISSION='sales.admin.integrations.manage';
    public function __construct(private CommerceOrderSourcePort $source,private SalesOrderSourceRepositoryInterface $sources,
        private SalesOrderRepositoryInterface $orders,private SalesMutationReceiptRepositoryInterface $receipts,
        private SalesMutationGuard $guard,private SalesOrderService $native,private SalesOfferReferencePort $references,
        private \Domains\Sales\Application\Contract\SalesOrderJourneyStore $journeys){}

    public function page(string $org,int $actor,string $channel,string $connection,?string $cursor):array
    {
        $this->guard->run($org,$actor,static fn()=>null,permission:self::PERMISSION);
        $page=$this->source->page($org,$channel,$connection,$cursor);
        $this->guard->run($org,$actor,static fn()=>null,permission:self::PERMISSION);
        return $page;
    }
    public function pending(string $org,int $actor,string $channel,string $connection):array
    {
        return $this->guard->run($org,$actor,fn()=>['deliveries'=>$this->source->pending($org,$channel,$connection)],permission:self::PERMISSION);
    }
    public function reconcile(string $org,int $actor,array $input,string $key,string $correlation):array
    {
        if($org===''||strlen($org)>40||$actor<1||trim($key)===''||strlen($key)>191||trim($correlation)===''||strlen($correlation)>191)throw new DomainException('Invalid reconciliation identity.');
        $channel=$input['channel']??null;$connection=$input['connection_id']??null;$external=$input['external_order_id']??null;$delivery=$input['delivery_id']??null;
        if($channel!=='shopify'||!is_string($connection)||preg_match('/^[a-f0-9]{32}$/D',$connection)!==1||!is_string($external)||preg_match('#^gid://shopify/Order/[1-9][0-9]{0,18}$#D',$external)!==1||
            ($delivery!==null&&(!is_string($delivery)||trim($delivery)===''||strlen($delivery)>190)))throw new DomainException('Invalid source identity.');
        if(array_diff(array_keys($input),['channel','connection_id','external_order_id','delivery_id'])!==[])throw new DomainException('Unexpected source field.');
        $key=trim($key);$op='order.source.reconcile';$fingerprint=hash('sha256',json_encode([$actor,$channel,$connection,$external,$delivery],JSON_THROW_ON_ERROR));
        return $this->guard->run($org,$actor,function()use($org,$actor,$channel,$connection,$external,$delivery,$key,$op,$fingerprint,$correlation):array{
            $pending='pending:'.bin2hex(random_bytes(16));
            if(!$this->receipts->claim($org,$op,$key,$pending)){
                $receipt=$this->receipts->find($org,$op,$key);
                if(!is_string($receipt)||!hash_equals($fingerprint,$receipt))throw new DomainException('Order reconciliation receipt conflict.');
                $row=$this->sources->lock($org,$channel,$connection,$external);
                return $this->result($org,$actor,$row['source_id'],$row['order_id'],'replayed',$row['blocked_reason'],true);
            }
            if($delivery!==null)$this->source->assertDelivery($org,$channel,$connection,$delivery,$external);
            $snapshot=$this->source->order($org,$channel,$connection,$external);
            if(($snapshot['channel']??null)!==$channel||($snapshot['store_reference']??null)!==$connection||($snapshot['external_order_id']??null)!==$external)throw new DomainException('Provider source identity mismatch.');
            $hash=$snapshot['provider_hash']??null;$updated=self::sqlTime($snapshot['updated_at']??null);$created=self::sqlTime($snapshot['created_at']??null);
            if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||$updated<$created)throw new DomainException('Invalid provider observation.');
            $row=$this->sources->lock($org,$channel,$connection,$external);
            $id=$row['order_id'];$sourceId=$row['source_id'];$latest=$row['latest_updated_at'];
            if($id!==null)$this->native->read($org,$actor,$id);
            $reason=$snapshot['blocked_reason']??null;
            $outcome='observed';$advance=true;
            if($latest!==null&&$updated<$latest){$outcome='stale';$advance=false;$reason='older_provider_revision';}
            elseif($latest!==null&&$updated===$latest&&!hash_equals((string)$row['latest_hash'],$hash)){$outcome='conflict';$advance=false;$reason='same_revision_different_payload';}
            elseif($id===null){
                $terms=null;
                if($reason===null){
                    try {
                        if(!is_array($snapshot['terms']??null))throw new DomainException('Missing commercial terms.');
                        $terms=SalesOrderTerms::normalize($snapshot['terms'],1000);
                        if($terms['currency']!==$snapshot['currency']||$terms['total']!==$snapshot['original_total'])throw new DomainException('Commercial snapshot disagrees with provider totals.');
                        foreach($terms['lines'] as $line){
                            if($line['product_id']===null||$line['variant_id']===null)throw new DomainException('Order line must reference mapped Product.');
                            $ref=$this->references->resolve($org,[...$line,'kind'=>'product']);
                            if($ref===null||($ref['product_id']??null)!==$line['product_id']||($ref['variant_id']??null)!==$line['variant_id']||($ref['sku']??null)!==$line['sku'])throw new DomainException('Mapped order variant unavailable.');
                        }
                    }catch(DomainException){$reason='commercial_terms_require_review';}
                }
                if($reason!==null||$terms===null)$outcome='blocked';
                else{
                    $id=bin2hex(random_bytes(16));
                    $order=[...$terms,'order_id'=>$id,'owner_id'=>$actor,'deal_id'=>null,'customer_id'=>null,
                        'channel'=>$channel,'store_reference'=>$connection,'external_order_id'=>$external,'placed_at'=>$created,'created_by'=>$actor];
                    $fact=['schema_version'=>'1.0.0','event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.placed',
                        'organization_id'=>$org,'order_id'=>$id,'deal_id'=>null,'revision'=>1,'occurred_at'=>$snapshot['created_at'],
                        'channel'=>$channel,'store_reference'=>$connection,'external_order_id'=>$external,'currency'=>$terms['currency'],
                        'order_total'=>$terms['total'],'financial_evidence_reference'=>null,'realized_revenue'=>null,
                        'attribution_observations'=>(object)[],'attribution_status'=>'unresolved','provenance'=>'shopify_admin_read','correlation_id'=>$correlation];
                    $this->orders->create($org,$order,$terms['lines'],$fact);
                    $this->native->read($org,$actor,$id);
                    $outcome='imported';
                }
            }elseif(($snapshot['test']??null)!==false){$outcome='blocked';$reason='test_order';}
            else {
                // Current provider facts are stored but never overwrite canonical placed terms or Finance/stock state.
                // Preserve the reason for downstream review without asserting a business transition.
                if($latest!==null&&$updated===$latest)$outcome='unchanged';
            }
            $observation=array_intersect_key($snapshot,array_flip(['channel','store_reference','external_order_id','created_at','updated_at','financial_status','fulfillment_status','cancelled_at','test','edited','tax_included','currency','original_total','current_total']));
            $this->sources->observe($org,$sourceId,$updated,$hash,$observation,$outcome,$reason,$id,$advance);
            // Journey readiness is independent of immutable order placement and provider order updatedAt.
            if($id!==null&&$advance&&in_array($outcome,['imported','observed','unchanged'],true)&&is_array($snapshot['journey']??null))
                $this->journeys->recordJourney($org,$id,$connection,$external,$snapshot['journey']);
            // Existing native order + successful observation: acknowledge only the pointer, not downstream lifecycle.
            if($delivery!==null&&$id!==null&&in_array($outcome,['imported','observed','unchanged'],true))
                $this->source->acknowledge($org,$channel,$connection,$delivery,$external);
            if(!$this->receipts->complete($org,$op,$key,$pending,$fingerprint))throw new RuntimeException('Order reconciliation receipt completion failed.');
            return $this->result($org,$actor,$sourceId,$id,$outcome,$reason,false);
        },permission:self::PERMISSION,targetOwner:$actor);
    }

    private function result(string $org,int $actor,string $sourceId,?string $id,string $outcome,?string $reason,bool $replayed):array
    {
        if($id!==null)$this->native->read($org,$actor,$id);
        return ['source_id'=>$sourceId,'order_id'=>$id,'outcome'=>$outcome,'blocked_reason'=>$reason,'replayed'=>$replayed];
    }
    private static function sqlTime(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$value)!==1)throw new DomainException('Invalid provider revision timestamp.');
        return str_replace('T',' ',substr($value,0,-1));
    }
}
