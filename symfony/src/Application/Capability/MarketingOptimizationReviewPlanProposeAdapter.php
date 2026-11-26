<?php
declare(strict_types=1);
namespace App\Application\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Marketing\MarketingOptimizationReviewPlanProposalService;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingOptimizationReviewPlanProposeAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private MarketingOptimizationReviewPlanProposalService $plans){}
    public function binding():string{return 'command:marketing.optimization.rule.plan.propose';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {return $this->plans->propose($actor,$input,$key);}
}
