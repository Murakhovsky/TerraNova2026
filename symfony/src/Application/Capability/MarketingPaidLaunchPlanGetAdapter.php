<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingPaidLaunchPlanGetAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidLaunchPlanService $plans){}
    public function binding():string{return 'query:marketing.paid.launch_plan.get';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->plans->get($actor,$input['plan_id']);
    }
}
