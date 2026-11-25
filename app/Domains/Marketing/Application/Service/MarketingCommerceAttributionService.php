<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingCommerceAttributionStore;
use Domains\Marketing\Application\Contract\MarketingCommerceOrderReadPort;
use Domains\Marketing\Application\Contract\MarketingAttributionStore;
use Domains\Marketing\Domain\MarketingAttributionPolicy;
use Domains\Marketing\Domain\MarketingCommerceAttributionPolicy as Policy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
final readonly class MarketingCommerceAttributionService
{
    public function __construct(private MarketingCampaignService $campaigns,private MarketingPaidLaunchPlanService $plans,
        private MarketingCommerceOrderReadPort $source,private MarketingCommerceAttributionStore $orders,
        private MarketingAttributionStore $receipts,private ActiveModuleResolver $modules,
        private ?\Domains\Marketing\Application\Contract\MarketingCommerceFinancialReadPort $financial=null){}
    private function scope(TenantContext $actor,string $campaign,?string $plan):?array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($actor->organizationId()->value(),'marketing')
            ||!preg_match('/^mkt-[a-f0-9]{24}$/D',$campaign)||$this->campaigns->get($actor,$campaign)===null)
            throw new DomainException('Active tenant Marketing campaign required.');
        $this->source->assertAvailable($actor);
        if($plan===null)return null;
        $p=$this->plans->get($actor,$plan);
        if($p['campaign_id']!==$campaign)throw new DomainException('Paid plan mismatch.');
        return $p;
    }
    private function fact(TenantContext $actor,string $campaign,?array $plan,string $order):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$order))throw new DomainException('Invalid Order ID.');
        $s=$this->source->get($actor,$order)??throw new DomainException('Native placement unavailable.');
        return Policy::evidence($s,$actor->organizationId()->value(),$order,$campaign,$plan);
    }
    public function inspect(TenantContext $actor,string $campaign,?string $plan,string $order):array
    {
        $p=$this->scope($actor,$campaign,$plan);
        try{$e=$this->fact($actor,$campaign,$p,$order);return ['state'=>'MATCHED','evidence'=>$e,'financial_mutation'=>false];}
        catch(DomainException){return ['state'=>'UNRESOLVED','evidence'=>null,'financial_mutation'=>false];}
    }
    public function capture(TenantContext $actor,string $campaign,?string $plan,array $orderIds,string $key):array
    {
        $p=$this->scope($actor,$campaign,$plan);$org=$actor->organizationId()->value();
        if(!array_is_list($orderIds)||count($orderIds)<1||count($orderIds)>50)throw new DomainException('Choose 1-50 native Order IDs.');
        foreach($orderIds as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid Order ID.');
        if(count(array_unique($orderIds))!==count($orderIds))throw new DomainException('Duplicate Order IDs.');
        sort($orderIds,SORT_STRING);
        $h=hash('sha256',json_encode([Policy::MODEL,$actor->userId()->value(),$campaign,$plan,$orderIds],JSON_THROW_ON_ERROR));
        return $this->receipts->batch($org,$key,$h,function()use($actor,$org,$campaign,$p,$orderIds):array{
            $results=[];
            foreach($orderIds as $id){
                try{$e=$this->fact($actor,$campaign,$p,$id);$r=$this->orders->record($org,$campaign,$e);
                    $results[]=['order_id'=>$id,'state'=>'RECORDED','evidence_id'=>$r['id']];
                }catch(DomainException){$results[]=['order_id'=>$id,'state'=>'REJECTED','evidence_id'=>null];}
            }
            return ['model'=>Policy::MODEL,'results'=>$results,'financial_mutation'=>false];
        });
    }
    public function report(TenantContext $actor,string $campaign,?string $plan,string $from,string $to):array
    {
        MarketingAttributionPolicy::window($from,$to);$p=$this->scope($actor,$campaign,$plan);$org=$actor->organizationId()->value();
        $rows=$this->orders->list($org,$campaign,$plan,$from,$to);
        if(count($rows)>500)throw new DomainException('Commerce cohort exceeds 500 captured orders; narrow date window.');
        $counts=[];$money=[];$count=0;$unresolved=0;$plans=[];$confirmed=[];$financialCount=0;$financialInvalid=0;$lifecycleCount=0;$lifecycleInvalid=0;$lifecycleUnavailable=0;$statuses=['placed'=>0,'confirmed'=>0,'cancelled'=>0,'completed'=>0];$returnedOrders=0;
        foreach($rows as $r){
            try{
                $id=$r['plan_id'];$bound=$p;
                if($plan===null&&$id!==null){
                    if(!isset($plans[$id]))$plans[$id]=$this->scope($actor,$campaign,$id);
                    $bound=$plans[$id];
                }
                $fresh=$this->fact($actor,$campaign,$bound,$r['order_id']);
                if(!hash_equals($r['evidence_hash'],$fresh['evidence_hash']))throw new DomainException('Order evidence changed.');
            }catch(DomainException){$unresolved++;continue;}
            $count++;$c=$fresh['currency'];$money[$c]=($money[$c]??0)+$fresh['placed_total_minor'];
            $k=$fresh['channel'].'/'.$fresh['utm_source'].'/'.$fresh['utm_medium'].'/'.$fresh['basis'];
            $counts[$k]??=['channel'=>$fresh['channel'],'source'=>$fresh['utm_source'],'medium'=>$fresh['utm_medium'],'basis'=>$fresh['basis'],'orders'=>0];
            $counts[$k]['orders']++;
            try{
                $state=$this->source instanceof \Domains\Marketing\Application\Contract\MarketingCommerceLifecycleReadPort
                    ?\Domains\Marketing\Domain\MarketingCommerceLifecyclePolicy::project($org,$fresh['order_id'],
                        fn(int $cursor,int $limit)=>$this->source->lifecycle($actor,$fresh['order_id'],$cursor,$limit)):null;
                if($state===null)$lifecycleUnavailable++;
                else{$lifecycleCount++;$statuses[$state['status']]++;if($state['returned_quantity']>0)$returnedOrders++;}
            }catch(DomainException|\LogicException $e){$lifecycleInvalid++;}
            try{
                $f=$this->financial?->financial($actor,$fresh['order_id']);
                if($f!==null){
                    $v=\Domains\Marketing\Domain\MarketingCommerceFinancialPolicy::verify($f,$org,$fresh['order_id'],$c);
                    $confirmed[$c]??=['currency'=>$c,'captured_minor'=>0,'refunded_minor'=>0,'chargeback_minor'=>0,'net_collected_minor'=>0];
                    foreach($v as $field=>$amount)$confirmed[$c][$field]+=$amount;
                    $financialCount++;
                }
            }catch(DomainException){$financialInvalid++;}
        }
        ksort($money);ksort($counts);$totals=[];foreach($money as $currency=>$minor)$totals[]=['currency'=>$currency,'minor'=>$minor];
        ksort($confirmed);$complete=$count>0&&$count===$financialCount&&$unresolved===0;
        $net=$complete?array_map(static fn(array $v)=>['currency'=>$v['currency'],'minor'=>$v['net_collected_minor']],array_values($confirmed)):null;
        return ['model'=>Policy::MODEL,'campaign_id'=>$campaign,'plan_id'=>$plan,'window_start'=>$from,'window_end'=>$to,
            'coverage'=>'CAPTURED_PLACEMENTS_ONLY','placed_orders'=>$count,'unresolved_captured_orders'=>$unresolved,
            'placed_order_value'=>$totals,'channels'=>array_values($counts),'recognized_revenue'=>null,'net_cash_received'=>$net,
            'financial_evidence_count'=>$financialCount,'financial_evidence_invalid_count'=>$financialInvalid,
            'financial_evidence_status'=>$complete?'COMPLETE_CAPTURED_COHORT':($financialCount>0?'PARTIAL':($count>0||$unresolved>0?'NO_EVIDENCE':'NO_CAPTURED_ORDERS')),
            'confirmed_financial_totals'=>array_values($confirmed),
            'lifecycle'=>['coverage'=>$lifecycleCount===$count&&$count>0&&$unresolved===0?'COMPLETE_CAPTURED_COHORT':($lifecycleCount>0?'PARTIAL':'UNAVAILABLE'),
                'evidence_count'=>$lifecycleCount,'unavailable_count'=>$lifecycleUnavailable,'invalid_count'=>$lifecycleInvalid,
                'current_status_counts'=>$statuses,'orders_with_returns'=>$returnedOrders],
            'roas'=>null,'causal_attribution'=>false,'deduplicated_against_deals'=>false];
    }
}
