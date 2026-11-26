<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Domain\MarketingAudienceOptimizationPolicy;
use Kernel\Tenant\Model\TenantContext;

final readonly class MarketingAudienceOptimizationService
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MarketingBudgetOptimizationService $performance){}
    public function preview(TenantContext $actor,string $firstPlan,string $firstSnapshot,string $secondPlan,string $secondSnapshot):array
    {
        $a=$this->plans->comparisonPins($actor,$firstPlan);
        $b=$this->plans->comparisonPins($actor,$secondPlan);
        $first=$this->performance->comparisonSource($actor,$firstPlan,$firstSnapshot);
        $second=$this->performance->comparisonSource($actor,$secondPlan,$secondSnapshot);
        if($a!==$this->plans->comparisonPins($actor,$firstPlan)||$b!==$this->plans->comparisonPins($actor,$secondPlan))
            throw new DomainException('Comparison planning pins changed during read.');
        return MarketingAudienceOptimizationPolicy::preview([...$first,...$a],[...$second,...$b]);
    }
}
