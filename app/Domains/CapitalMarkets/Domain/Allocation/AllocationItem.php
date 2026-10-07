<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Allocation;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class AllocationItem {
 public function __construct(public string $strategyVersionId,public string $opportunityId,public Decimal $requestedCapital,public Decimal $approvedCapital,public int $priority,public Decimal $expectedNetReturn,public Decimal $expectedValue,public Decimal $capacity,public string $decision,public string $reason,public array $riskBudget=[]){}
}
