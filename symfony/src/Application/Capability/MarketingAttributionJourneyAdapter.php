<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingTouchAttributionService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingAttributionJourneyAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingTouchAttributionService $service){}
    public function binding():string{return 'query:marketing.attribution.journey.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->report($actor,$input['order_id'],$input['model']);}
}
