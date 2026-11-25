<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingAnalyticsService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingAttributionCaptureAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingAnalyticsService $service){}
    public function binding():string{return 'command:marketing.attribution.capture';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->capture($actor,$input['campaign_id'],$input['plan_id'],$input['lead_ids'],$key);}
}
