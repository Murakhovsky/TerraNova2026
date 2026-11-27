<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DomainException;
use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;
use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use Domains\Sales\Application\Contract\SalesTeamScopeInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\Sales\Domain\Policy\SalesOrderTerms;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;

/** Native human intake; channel adapters will use a separate authenticated source contract. */
final readonly class SalesOrderService
{
    public function __construct(private SalesOrderRepositoryInterface $orders,private SalesMutationReceiptRepositoryInterface $receipts,
        private SalesMutationGuard $guard,private SalesEntityScopeService $scope,private SalesOfferReferencePort $references,private ?CommerceOrderFinancialEvidenceReadPort $finance=null,private ?SalesTeamScopeInterface $teams=null){}

    public function create(string $org,int $actor,array $input,string $key,string $correlation):array
    {
        if ($org===''||$actor<1||trim($key)===''||strlen($key)>191||trim($correlation)===''||strlen($correlation)>191) throw new DomainException('Invalid native order identity.');
        $terms=SalesOrderTerms::normalize($input);
        $owner=$input['owner_id']??$actor;$deal=$input['deal_id']??null;$requestedCustomer=$input['customer_id']??null;
        if (!is_int($owner)||$owner<1||($deal!==null&&(!is_int($deal)||$deal<1))
            ||(array_key_exists('customer_id',$input)&&(!is_int($requestedCustomer)||$requestedCustomer<1))) throw new DomainException('Invalid order relationship.');
        $key=trim($key);$operation='order.native.create';
        $hash=hash('sha256',json_encode($requestedCustomer===null?[$actor,$owner,$deal,$terms]:[$actor,$owner,$deal,$terms,$requestedCustomer],JSON_THROW_ON_ERROR));
        return $this->guard->run($org,$actor,function()use($org,$actor,$owner,$deal,$terms,$operation,$key,$hash,$correlation,$requestedCustomer):array{
            if ($requestedCustomer!==null) $this->guard->run($org,$actor,static fn()=>null,'person',$requestedCustomer);
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,$operation,$key,$pending)) {
                $receipt=$this->receipts->find($org,$operation,$key);
                if ($receipt===null||preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$receipt,$m)!==1||!hash_equals($hash,$m[1])) throw new DomainException('Order receipt conflict.');
                $order=$this->orders->get($org,$m[2],true);
                $this->authorize($org,$actor,$order);
                if ($requestedCustomer!==null&&$order['customer_id']!==$requestedCustomer) throw new DomainException('Order customer receipt mismatch.');
                return ['order'=>$order,'replayed'=>true];
            }
            foreach ($terms['lines'] as $line) {
                if ($line['product_id']===null) continue;
                $ref=$this->references->resolve($org,[...$line,'kind'=>'product']);
                if ($ref===null||($ref['product_id']??null)!==$line['product_id']||($ref['variant_id']??null)!==$line['variant_id']||($ref['sku']??null)!==$line['sku']) throw new DomainException('Product variant unavailable.');
            }
            $customer=$deal===null?$requestedCustomer:$this->orders->customerForDeal($org,$deal);
            if ($requestedCustomer!==null&&$customer!==$requestedCustomer) throw new DomainException('Order customer differs from linked Deal.');
            if ($deal!==null&&$customer===null) throw new DomainException('Order deal unavailable.');
            $id=bin2hex(random_bytes(16));$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $order=[...$terms,'order_id'=>$id,'owner_id'=>$owner,'deal_id'=>$deal,'customer_id'=>$customer,'channel'=>'native',
                'store_reference'=>'manual','external_order_id'=>$id,'placed_at'=>$now->format('Y-m-d H:i:s.u'),'created_by'=>$actor];
            $evidence=['schema_version'=>'1.0.0','event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.placed',
                'organization_id'=>$org,'order_id'=>$id,'deal_id'=>$deal,'revision'=>1,'occurred_at'=>$now->format('Y-m-d\TH:i:s.u\Z'),
                'channel'=>'native','store_reference'=>'manual','external_order_id'=>$id,'currency'=>$terms['currency'],
                'order_total'=>$terms['total'],'financial_evidence_reference'=>null,'realized_revenue'=>null,
                'attribution_observations'=>(object)$terms['attribution'],'attribution_status'=>'unresolved','provenance'=>'native_operator',
                'correlation_id'=>$correlation];
            $this->orders->create($org,$order,$terms['lines'],$evidence);
            $state=$this->orders->get($org,$id,true);
            $this->authorize($org,$actor,$state);
            if (!$this->receipts->complete($org,$operation,$key,$pending,$hash.':'.$id)) throw new RuntimeException('Order receipt completion failed.');
            return ['order'=>$state,'replayed'=>false];
        },$deal===null?null:'deal',$deal,$owner);
    }

    public function read(string $org,int $actor,string $id):array
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$id)!==1) throw new DomainException('Order unavailable.');
        $order=$this->orders->get($org,$id);$this->authorize($org,$actor,$order);
        $financial=$this->finance?->financialEvidence($org,$id);
        $this->authorize($org,$actor,$order);
        return ['order'=>$order,'financial_evidence'=>$financial];
    }

    /** No implicit director access: tenant scope must be granted live. */
    public function list(string $org,int $actor,array $input):array
    {
        $mode=$input['scope']??'self';$owner=$input['owner_id']??null;
        $query=$input['q']??'';$offset=$input['offset']??0;$limit=$input['limit']??20;
        if(!in_array($mode,['self','owner','tenant','team','auto'],true)||($mode==='owner'&&(!is_int($owner)||$owner<1))||
            ($mode!=='owner'&&array_key_exists('owner_id',$input))||!is_string($query)||mb_strlen($query)>120||
            !is_int($offset)||$offset<0||$offset>200000||!is_int($limit)||$limit<1||$limit>100)
            throw new DomainException('Invalid order scope or page.');
        if($mode==='auto'){
            try{$this->scope->resolve($org,$actor,'tenant');$mode='tenant';}
            catch(DomainException){$mode='team';}
        }
        if($mode==='team'){
            $self=$this->scope->resolve($org,$actor,'self');
            $owners=$this->teams?->ledMembers($org,$actor)??[$self];
            $mode=count($owners)>1?'team':'self';
        }else{
            $owner=$this->scope->resolve($org,$actor,$mode,$owner);
            $owners=$owner===null?null:[$owner];
        }
        $rows=$this->orders->workspacePage($org,$owners,trim($query),$offset,$limit+1);
        foreach($rows as $row){
            // Fail closed on changed/mismatched live authorization. No partial page of private records.
            if($owners!==null&&!in_array($row['owner_id'],$owners,true))throw new DomainException('Order page owner mismatch.');
            $this->authorize($org,$actor,$row);
        }
        return ['items'=>array_slice($rows,0,$limit),'has_more'=>count($rows)>$limit,'scope'=>$mode];
    }

    private function authorize(string $org,int $actor,?array $order):void
    {
        if ($order===null) throw new DomainException('Order unavailable.');
        try {$this->scope->resolve($org,$actor,'owner',$order['owner_id']);}
        catch (DomainException) {$this->scope->resolve($org,$actor,'tenant');}
        if ($order['deal_id']!==null) $this->scope->requireEntity($org,$actor,'deal',$order['deal_id']);
    }
}
