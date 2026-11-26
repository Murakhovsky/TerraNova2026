<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Marketing\MarketingOptimizationReplacementPlanProposalService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationReplacementPlanProposeAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationReplacementPlanProposalService $plans){}
    public function binding():string{return 'command:marketing.optimization.replacement.plan.propose';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->plans->propose($actor,$input,$key);}
}
