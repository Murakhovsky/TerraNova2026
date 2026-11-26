<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Marketing\MarketingOptimizationLearningPlanProposalService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationLearningPlanProposeAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationLearningPlanProposalService $service){}
    public function binding():string{return 'command:marketing.optimization.learning.plan.propose';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->service->propose($actor,$input,$key);}
}
