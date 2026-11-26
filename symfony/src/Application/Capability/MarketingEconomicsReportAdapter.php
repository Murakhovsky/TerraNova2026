<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingEconomicsService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingEconomicsReportAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingEconomicsService $service){}
    public function binding():string{return 'query:marketing.analytics.economics.report';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->report($actor,$input['plan_id'],$input['reservation_id'],$input['conversion_through']??null);}
}
