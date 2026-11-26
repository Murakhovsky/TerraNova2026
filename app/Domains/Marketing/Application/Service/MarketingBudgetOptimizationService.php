<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Domain\MarketingBudgetOptimizationPolicy;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingBudgetOptimizationService
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MarketingPaidOperationsReadService $operations,
        private MarketingAnalystPerformanceService $performance){}
    public function preview(TenantContext $actor,string $firstPlan,string $firstSnapshot,string $secondPlan,string $secondSnapshot):array
    {
        return MarketingBudgetOptimizationPolicy::preview($this->comparisonSource($actor,$firstPlan,$firstSnapshot),$this->comparisonSource($actor,$secondPlan,$secondSnapshot));
    }
    /** Internal exact native performance projection, not a public Capability. */
    public function comparisonSource(TenantContext $actor,string $id,string $snapshot):array
    {
        $p=$this->plans->get($actor,$id);
        if($p['period_end']>=gmdate('Y-m-d'))throw new DomainException('Completed UTC reporting window required for budget hypothesis.');
        // The current native writer only accepts a TRAFFIC / single creative / automatic-placement plan.
        // Require all independently recorded terminal operations before comparison.
        $terminal=$this->operations->settlementSource($actor,$id,$snapshot);
        $o=$terminal['observation'];
        $report=$this->performance->observe($actor,$p['campaign_id'],$p['campaign_revision'],$p['adset_id'],$p['adset_revision'],$id,$snapshot);
        $m=$report['facts'];$pins=array_column($report['sources'],null,'alias');
        if($o['source_revision']!==$pins['media_snapshot']['revision']||$o['source_hash']!==$pins['media_snapshot']['content_hash']
            ||$terminal['plan']['plan_hash']!==$p['plan_hash'])throw new DomainException('Optimization source changed.');
        return ['plan_id'=>$id,'plan_hash'=>$p['plan_hash'],'campaign_id'=>$p['campaign_id'],'provider_campaign_id'=>$terminal['terminal']['provider_campaign_id'],
            'account_id'=>$p['account_id'],'account_revision'=>$p['account_revision'],'currency'=>$p['currency'],'window_start'=>$p['period_start'],
            'window_end'=>$p['period_end'],'daily_minor'=>$p['daily_minor'],'lifetime_minor'=>$p['lifetime_minor'],
            'attribution_hash'=>$o['attribution_hash'],'snapshot_ref'=>$snapshot,'source_revision'=>$o['source_revision'],'source_hash'=>$o['source_hash'],
            'reported_spend_decimal'=>$m['reported_spend_decimal'],'impressions'=>$m['impressions'],'clicks'=>$m['clicks']];
    }
}
