<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Domain\MarketingOptimizationRulePolicy;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationRuleService
{
    public function __construct(private MarketingMediaAnalyticsService $media){}

    public function evaluate(TenantContext $actor,array $in):array
    {
        if($in['window_end']>=gmdate('Y-m-d')||$in['baseline_end']>=gmdate('Y-m-d'))
            throw new DomainException('Optimization rules require completed UTC windows.');
        $read=fn(string $start,string $end):array=>$this->media->report($actor,$in['account_id'],'campaign',$start,$end,null,null);
        $current=$read($in['window_start'],$in['window_end']);$baseline=$read($in['baseline_start'],$in['baseline_end']);
        if($current['timezone']!=='UTC'||$baseline['timezone']!=='UTC'
            ||$current['id']!==$in['current_run_id']||$baseline['id']!==$in['baseline_run_id'])
            throw new DomainException('Pinned rule inputs differ from latest native batches.');
        $answer=MarketingOptimizationRulePolicy::evaluate($current,$baseline,$in['external_id'],$in['rule'],$in['threshold_percent']);
        if($read($in['window_start'],$in['window_end'])!==$current||$read($in['baseline_start'],$in['baseline_end'])!==$baseline)
            throw new DomainException('Rule evidence changed during evaluation.');
        return $answer;
    }
}
