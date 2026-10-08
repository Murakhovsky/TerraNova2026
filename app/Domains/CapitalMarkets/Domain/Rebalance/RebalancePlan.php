<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Rebalance;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class RebalancePlan {
 public function __construct(public string $id,public string $portfolioId,public array $currentAllocation,public array $targetAllocation,public array $actions,public Decimal $estimatedCosts,public int $expectedRiskImprovementPct,public Decimal $expectedReturnImpact,public string $decision,public string $reason){}
}
