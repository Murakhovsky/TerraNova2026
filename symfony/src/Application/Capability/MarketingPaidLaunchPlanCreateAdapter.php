<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Kernel\Tenant\Model\TenantContext;
final readonly class MarketingPaidLaunchPlanCreateAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingPaidLaunchPlanService $plans){}
    public function binding():string{return 'command:marketing.paid.launch_plan.create';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        return $this->plans->create($actor,$input['campaign_id'],$input['campaign_revision'],$input['adset_id'],$input['adset_revision'],
            $input['account_id'],$input['account_revision'],$input['period_start'],$input['period_end'],$key);
    }
}
