<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingOptimizationMonitor;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingMonitorAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationMonitor $service){}
    public function binding():string{return 'query:marketing.analytics.monitor.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->report($actor,$input['account_id'],$input['grain'],$input['window_start'],$input['window_end'],$input['baseline_start'],$input['baseline_end']);}
}
