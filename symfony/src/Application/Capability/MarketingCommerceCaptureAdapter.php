<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingCommerceAttributionService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingCommerceCaptureAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingCommerceAttributionService $service){}
    public function binding():string{return 'command:marketing.attribution.commerce.capture';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->capture($actor,$input['campaign_id'],$input['plan_id'],$input['order_ids'],$key);}
}
