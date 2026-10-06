<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;

final readonly class CompensationDecision extends ValueObject
{
    public function __construct(
        public ExecutionGroupState $nextState,
        public ?CompensationPolicy $policy,
        public Decimal $unhedgedQuantity,
        public string $reason,
    ){}
}
