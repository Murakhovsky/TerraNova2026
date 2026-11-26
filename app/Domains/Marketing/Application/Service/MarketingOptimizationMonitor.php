<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use Domains\Marketing\Domain\MarketingOptimizationMonitorPolicy;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingOptimizationMonitor
{
    public function __construct(private MarketingMediaAnalyticsService $media){}
    public function report(TenantContext $actor,string $account,string $grain,string $from,string $to,string $baselineFrom,string $baselineTo):array
    {
        $a=$this->media->report($actor,$account,$grain,$from,$to,null,null);
        $b=$this->media->report($actor,$account,$grain,$baselineFrom,$baselineTo,null,null);
        return MarketingOptimizationMonitorPolicy::analyze($a,$b);
    }
}
