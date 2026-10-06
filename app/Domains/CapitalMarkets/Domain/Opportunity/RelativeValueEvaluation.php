<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Opportunity;

use Domains\CapitalMarkets\Domain\Research\ResearchResultStatus;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;

final readonly class RelativeValueEvaluation extends ValueObject
{
    /** @param list<string> $reasons @param array<string,mixed> $evidence */
    public function __construct(
        public OpportunityType $type,
        public ResearchResultStatus $status,
        public Decimal $expectedNetCashflow,
        public array $reasons=[],
        public array $evidence=[],
    ){}

    public function executable():bool{return $this->status===ResearchResultStatus::Validated&&$this->expectedNetCashflow->isPositive();}
}
