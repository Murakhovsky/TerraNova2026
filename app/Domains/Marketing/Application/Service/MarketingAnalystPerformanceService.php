<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Domain\MarketingAttributionPolicy;
use Kernel\Tenant\Model\TenantContext;

/** Native bound report only; never payment, revenue, or causal attribution. */
final readonly class MarketingAnalystPerformanceService
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MarketingPaidOperationsReadService $operations,
        private MarketingPaidReadService $paid,private MarketingPaidReadRepository $accounts){}
    public function observe(TenantContext $actor,string $campaign,int $campaignRevision,string $adset,int $adsetRevision,string $planId,string $snapshot):array
    {
        $p=$this->plans->get($actor,$planId);
        if($p['campaign_id']!==$campaign||$p['campaign_revision']!==$campaignRevision||$p['adset_id']!==$adset||$p['adset_revision']!==$adsetRevision)
            throw new DomainException('Performance plan differs from current planning pins.');
        $org=$actor->organizationId()->value();
        $a=$this->accounts->account($org,$p['account_id']);
        if($a===null||$a['revision']!==$p['account_revision']||$a['currency']!==$p['currency']||$a['timezone']!==$p['account_timezone'])
            throw new DomainException('Performance account revision differs.');
        $proof=$this->operations->compareSpend($actor,$planId,$snapshot);
        $before=$this->paid->spendEvidence($actor,$p['account_id'],$snapshot);
        $metric=null;
        foreach($this->paid->insights($actor,$p['account_id'],'campaign',$p['period_start'],$p['period_end']) as $row){
            if($row['id']===$snapshot&&$row['revision']===$proof['source_revision']){$metric=MarketingAttributionPolicy::media($row);break;}
        }
        if($metric===null||$before['source_revision']!==$proof['source_revision']
            ||$before['reported_spend_decimal']!==$proof['reported_spend_decimal']
            ||$metric['reported_spend_decimal']!==$proof['reported_spend_decimal']
            ||$this->paid->spendEvidence($actor,$p['account_id'],$snapshot)!==$before
            ||$this->accounts->account($org,$p['account_id'])!==$a)
            throw new DomainException('Performance evidence changed during read.');
        return ['sources'=>[
            ['alias'=>'paid_plan','record_id'=>$planId,'revision'=>1,'content_hash'=>$p['plan_hash']],
            ['alias'=>'media_snapshot','record_id'=>$snapshot,'revision'=>$before['source_revision'],'content_hash'=>$before['source_hash']]],
            'facts'=>['status'=>'NATIVE_BOUND_PROVIDER_REPORT','currency'=>$p['currency'],'window_start'=>$p['period_start'],'window_end'=>$p['period_end'],
                'source_status'=>$before['source_status'],'reported_spend_decimal'=>$before['reported_spend_decimal'],'planned_cap_minor'=>$p['lifetime_minor'],
                ...array_intersect_key($metric,array_flip(['impressions','clicks','ctr_percent','cpc','cpm'])),
                'recognized_revenue'=>null,'causal_attribution'=>false,'payment_confirmed'=>false]];
    }
}
