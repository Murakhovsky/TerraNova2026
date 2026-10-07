<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Allocation;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Risk\RiskBudget;
final readonly class StrategyCapitalAllocation {
 public function __construct(public string $strategyVersionId,public Decimal $allocatedCapital,public Decimal $reserved,public Decimal $deployed,public Decimal $available,public RiskBudget $riskBudget,public DateTimeImmutable $effectiveFrom,public string $status){}
}
