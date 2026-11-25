<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingMediaAnalyticsService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingMediaAnalyticsSyncAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingMediaAnalyticsService $service){}
    public function binding():string{return 'command:marketing.analytics.media.sync';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->sync($actor,$input['account_id'],$input['grain'],$input['window_start'],$input['window_end'],$key);}
}
