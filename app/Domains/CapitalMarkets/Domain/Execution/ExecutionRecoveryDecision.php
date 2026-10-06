<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionRecoveryDecision extends ValueObject
{
    public function __construct(
        public ExecutionRecoveryAction $action,
        public ExecutionGroupState $state,
        public Decimal $firstLegFilled,
        public Decimal $secondLegFilled,
        public Decimal $unhedgedQuantity,
        public string $reason,
    ){}
}
