<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DomainException;
use LogicException;
use DateTimeImmutable;
use DateTimeZone;
use Domains\ProductManagement\Application\Contract\OrderInventoryPort;
use Domains\Sales\Application\Contract\SalesOrderRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOrderLifecycleRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Kernel\Module\ActiveModuleResolver;

/** Sales owns business state. Product owns every physical stock effect, in the same native transaction. */
final readonly class SalesOrderLifecycleService
{
    private const OPERATIONS=['confirm','allocate','release','fulfill','cancel','return'];
    public function __construct(private SalesOrderRepositoryInterface $orders,private SalesOrderLifecycleRepositoryInterface $lifecycle,
        private SalesMutationReceiptRepositoryInterface $receipts,private SalesMutationGuard $guard,
        private SalesMutationAuthorityInterface $authority,private SalesEntityScopeService $scope,
        private OrderInventoryPort $inventory,private ActiveModuleResolver $modules,private \Domains\Sales\Application\Contract\SalesOrderSourceRepositoryInterface $sources){}

    public function read(string $org,int $actor,string $id):array
    {
        return $this->guard->run($org,$actor,function()use($org,$actor,$id):array{
            $order=$this->locked($org,$actor,$id);
            return $this->view($org,$order,false);
        });
    }
    public function execute(string $org,int $actor,string $operation,array $input,string $key,string $correlation):array
    {
        $id=$input['order_id']??null;$revision=$input['expected_revision']??null;$reference=$input['reference']??null;
        if($org===''||strlen($org)>40||$actor<1||!in_array($operation,self::OPERATIONS,true)||!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||
            !is_int($revision)||$revision<1||!is_string($reference)||!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,119}$#D',$reference)||
            trim($key)===''||strlen($key)>191||trim($correlation)===''||strlen($correlation)>191)throw new DomainException('Invalid order lifecycle command.');
        $usesRows=in_array($operation,['allocate','release','fulfill','return'],true);
        if(array_diff(array_keys($input),$usesRows?['order_id','expected_revision','reference','items']:['order_id','expected_revision','reference'])!==[])throw new DomainException('Unexpected order lifecycle field.');
        $items=$usesRows?$this->items($operation,$input['items']??null):[];
        $hash=hash('sha256',json_encode([$actor,$operation,$id,$revision,$reference,$items],JSON_THROW_ON_ERROR));
        $key=trim($key);$op='order.lifecycle.'.$operation;
        return $this->guard->run($org,$actor,function()use($org,$actor,$operation,$id,$revision,$reference,$items,$hash,$key,$op,$correlation):array{
            $order=$this->locked($org,$actor,$id);
            $pending='pending:'.bin2hex(random_bytes(16));
            if(!$this->receipts->claim($org,$op,$key,$pending)){
                $receipt=$this->receipts->find($org,$op,$key);
                if(!is_string($receipt)||!hash_equals($hash,$receipt))throw new DomainException('Order lifecycle receipt conflict.');
                return $this->view($org,$order,true);
            }
            if($order['revision']!==$revision)throw new DomainException('Order revision changed.');
            if($this->lifecycle->hasDocument($org,$id,$operation,$reference))throw new DomainException('Order operation document already recorded; use original receipt.');
            // A Shopify order may already have a provider commitment. Local stock effects are prohibited until explicit ownership cutover.
            if($order['channel']!=='native'&&$operation!=='confirm')throw new DomainException('External order inventory and fulfillment require verified ownership reconciliation.');
            if(!in_array($operation,['confirm','cancel'],true)&&!$this->modules->isEnabled($org,'product_management'))throw new DomainException('Product Management inactive.');
            $allocations=$this->lifecycle->allocations($org,$id);$status=$order['status'];$next=$status;$effects=[];
            $byLine=array_column($order['lines'],null,'line_no');$byAllocation=array_column($allocations,null,'allocation_id');
            if($operation==='confirm'){
                if($status!=='placed')throw new DomainException('Only a placed order can be confirmed.');
                $next='confirmed';
            }elseif($operation==='allocate'){
                if($status!=='confirmed')throw new DomainException('Only confirmed orders may be allocated.');
                if(count($allocations)+count($items)>1000)throw new DomainException('Order allocation limit requires operator review.');
                $used=[];foreach($allocations as $a)$used[$a['line_no']]=($used[$a['line_no']]??0)+$a['quantity']-$a['cancelled'];
                foreach($items as $item){
                    $line=$byLine[$item['line_no']]??null;
                    if($line===null||$line['product_id']===null||$line['variant_id']===null||($used[$item['line_no']]??0)+$item['quantity']>$line['quantity'])
                        throw new DomainException('Allocation requires a mapped line and remaining order quantity.');
                    $used[$item['line_no']]=($used[$item['line_no']]??0)+$item['quantity'];
                }
                // Deterministic variant/location ordering reduces cross-order stock lock conflicts.
                usort($items,fn($a,$b)=>[$byLine[$a['line_no']]['variant_id'],$a['location_id']??'',$a['line_no']]<=>[$byLine[$b['line_no']]['variant_id'],$b['location_id']??'',$b['line_no']]);
                foreach($items as $item){
                    $line=$byLine[$item['line_no']];$allocation=bin2hex(random_bytes(16));
                    $source=self::source($id,$item['line_no'],$allocation);
                    $r=$this->inventory->reserveForOrder($org,$line['product_id'],$line['variant_id'],$line['sku'],$item['location_id'],$item['quantity'],$source,
                        self::effectKey($id,$revision,$operation,$allocation),(string)$actor,$correlation);
                    $a=['allocation_id'=>$allocation,'line_no'=>$item['line_no'],'quantity'=>$item['quantity'],'location_id'=>$r['location_id'],'reservation_id'=>$r['reservation_id'],'stock_mode'=>$r['stock_mode'],'fulfilled'=>0,'cancelled'=>0,'returned'=>0];
                    $this->lifecycle->addAllocation($org,$id,$a);
                    $effects[]=['allocation_id'=>$allocation,'quantity'=>$item['quantity'],'stock_mode'=>$a['stock_mode'],'reservation_id'=>$a['reservation_id'],'movement_id'=>null];
                }
            }elseif($operation==='cancel'){
                if(!in_array($status,['placed','confirmed'],true))throw new DomainException('Only placed or confirmed orders may be cancelled.');
                foreach($allocations as $a){
                    $qty=$a['quantity']-$a['fulfilled']-$a['cancelled'];
                    if($qty>0){$effects[]=$this->settle($org,$actor,$id,$revision,'release',$a,$qty,$correlation);$a['cancelled']+=$qty;$this->lifecycle->updateAllocation($org,$id,$a);}
                }
                $next='cancelled';
            }else{
                if($operation==='return'){
                    if(!in_array($status,['confirmed','completed','cancelled'],true))throw new DomainException('Order has no accepted fulfillment.');
                }elseif($status!=='confirmed')throw new DomainException('Order is not open for fulfillment or release.');
                foreach($items as $item){
                    $a=$byAllocation[$item['allocation_id']]??null;
                    if($a===null)throw new DomainException('Order allocation unavailable.');
                    $qty=$item['quantity'];
                    if($operation==='return'){
                        if($qty>$a['fulfilled']-$a['returned'])throw new DomainException('Return exceeds fulfilled quantity.');
                        if($item['disposition']==='restock'&&$a['stock_mode']!=='local')throw new DomainException('Non-stock line cannot be physically restocked.');
                    }elseif($qty>$a['quantity']-$a['fulfilled']-$a['cancelled'])throw new DomainException('Operation exceeds remaining allocation.');
                }
                usort($items,fn($a,$b)=>[$byLine[$byAllocation[$a['allocation_id']]['line_no']]['variant_id'],$byAllocation[$a['allocation_id']]['location_id']??'',$a['allocation_id']]<=>[$byLine[$byAllocation[$b['allocation_id']]['line_no']]['variant_id'],$byAllocation[$b['allocation_id']]['location_id']??'',$b['allocation_id']]);
                foreach($items as $item){
                    $a=$byAllocation[$item['allocation_id']];$qty=$item['quantity'];
                    $stockOperation=match($operation){'fulfill'=>'commit','release'=>'release','return'=>'return'};
                    if($operation==='return'&&$item['disposition']==='no_restock'){
                        $effect=['allocation_id'=>$a['allocation_id'],'quantity'=>$qty,'stock_mode'=>$a['stock_mode'],'reservation_id'=>$a['reservation_id'],'movement_id'=>null];
                    }else $effect=$this->settle($org,$actor,$id,$revision,$stockOperation,$a,$qty,$correlation);
                    if($operation==='return'){$a['returned']+=$qty;$effect['disposition']=$item['disposition'];}
                    elseif($operation==='fulfill')$a['fulfilled']+=$qty;
                    else $a['cancelled']+=$qty;
                    $effects[]=$effect;$this->lifecycle->updateAllocation($org,$id,$a);
                }
                if($operation==='fulfill'){
                    $total=array_sum(array_column($order['lines'],'quantity'));
                    $fulfilled=array_sum(array_column($this->lifecycle->allocations($org,$id),'fulfilled'));
                    if($fulfilled===$total)$next='completed';
                }
            }
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $fact=['schema_version'=>'1.0.0','event_id'=>bin2hex(random_bytes(16)),'event_type'=>'sales.commerce.order.'.$operation,
                'organization_id'=>$org,'order_id'=>$id,'revision'=>$revision+1,'occurred_at'=>$now->format('Y-m-d\TH:i:s.u\Z'),
                'previous_status'=>$status,'status'=>$next,'actor_id'=>$actor,'reference'=>$reference,'effects'=>$effects,
                'financial_evidence_reference'=>null,'realized_revenue'=>null,'correlation_id'=>$correlation];
            $this->lifecycle->transition($org,$id,$revision,$next,$fact);
            if(!$this->receipts->complete($org,$op,$key,$pending,$hash))throw new LogicException('Order lifecycle receipt completion failed.');
            return $this->view($org,$this->locked($org,$actor,$id),false);
        });
    }
    private function locked(string $org,int $actor,string $id):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Order unavailable.');
        $order=$this->orders->get($org,$id,true);
        if($order===null)throw new DomainException('Order unavailable.');
        try {$this->scope->resolve($org,$actor,'owner',$order['owner_id']);}
        catch (DomainException) {$this->scope->resolve($org,$actor,'tenant');}
        if($order['deal_id']!==null)$this->authority->entity($org,$actor,'deal',$order['deal_id']);
        return $order;
    }
    private function settle(string $org,int $actor,string $id,int $revision,string $op,array $a,int $qty,string $correlation):array
    {
        $movement=null;
        if($a['stock_mode']==='local'){
            if(!$this->modules->isEnabled($org,'product_management'))throw new DomainException('Product Management inactive; reservation cannot be silently abandoned.');
            $result=$this->inventory->settleOrderReservation($org,$a['reservation_id'],self::source($id,$a['line_no'],$a['allocation_id']),$op,$qty,
                self::effectKey($id,$revision,$op,$a['allocation_id']),(string)$actor,$correlation);
            $movement=$result['movement_id'];
        }
        return ['allocation_id'=>$a['allocation_id'],'quantity'=>$qty,'stock_mode'=>$a['stock_mode'],'reservation_id'=>$a['reservation_id'],'movement_id'=>$movement];
    }
    private function view(string $org,array $order,bool $replayed):array
    {
        $allocations=$this->lifecycle->allocations($org,$order['order_id']);$total=array_sum(array_column($order['lines'],'quantity'));
        $fulfilled=array_sum(array_column($allocations,'fulfilled'));$returned=array_sum(array_column($allocations,'returned'));
        $reserved=0;foreach($allocations as $a)$reserved+=$a['quantity']-$a['fulfilled']-$a['cancelled'];
        $external=$order['channel']!=='native';
        return ['order_id'=>$order['order_id'],'status'=>$order['status'],'revision'=>$order['revision'],'inventory_authority'=>$external?'external_review':'local',
            'external_observation'=>$external?$this->sources->latestForOrder($org,$order['order_id']):null,
            'ordered_quantity'=>$total,'allocated_pending_quantity'=>$reserved,'fulfilled_quantity'=>$fulfilled,'returned_quantity'=>$returned,
            'cancelled_quantity'=>$order['status']==='cancelled'?$total-$fulfilled:0,
            'unallocated_quantity'=>$order['status']==='cancelled'?0:$total-$fulfilled-$reserved,
            'fulfillment_state'=>$external?'external_review':($fulfilled===0?'unfulfilled':($fulfilled===$total?'fulfilled':'partial')),
            'return_state'=>$returned===0?'none':($returned===$fulfilled?'returned':'partial'),'allocations'=>$allocations,'replayed'=>$replayed];
    }
    private function items(string $op,mixed $rows):array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>100)throw new DomainException('Invalid order operation items.');
        $out=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row)||!is_int($row['quantity']??null)||$row['quantity']<1||$row['quantity']>100000)throw new DomainException('Invalid operation quantity.');
            if($op==='allocate'){
                if(!is_int($row['line_no']??null)||$row['line_no']<1||$row['line_no']>1000||!array_key_exists('location_id',$row)||
                    ($row['location_id']!==null&&(!is_string($row['location_id'])||!preg_match('/^[a-f0-9]{32}$/D',$row['location_id'])))||
                    array_diff(array_keys($row),['line_no','location_id','quantity'])!==[])throw new DomainException('Invalid allocation request.');
                $normalized=['line_no'=>$row['line_no'],'location_id'=>$row['location_id'],'quantity'=>$row['quantity']];
                $key=$row['line_no'].':'.($row['location_id']??'nonstock');
            }else{
                if(!is_string($row['allocation_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$row['allocation_id'])||
                    array_diff(array_keys($row),$op==='return'?['allocation_id','quantity','disposition']:['allocation_id','quantity'])!==[])throw new DomainException('Invalid allocation settlement.');
                $normalized=['allocation_id'=>$row['allocation_id'],'quantity'=>$row['quantity']];
                if($op==='return'){
                    if(!in_array($row['disposition']??null,['restock','no_restock'],true))throw new DomainException('Explicit return disposition required.');
                    $normalized['disposition']=$row['disposition'];
                }
                $key=$row['allocation_id'];
            }
            if(isset($seen[$key]))throw new DomainException('Duplicate order operation item.');
            $seen[$key]=true;$out[$key]=$normalized;
        }
        ksort($out,SORT_STRING);return array_values($out);
    }
    private static function source(string $order,int $line,string $allocation):string{return 'sales-order:'.$order.':'.$line.':'.$allocation;}
    private static function effectKey(string $order,int $revision,string $op,string $allocation):string{return 'sales-order:'.hash('sha256',json_encode([$order,$revision,$op,$allocation],JSON_THROW_ON_ERROR));}
}
