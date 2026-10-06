<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class RelativeValueExitPolicy extends ValueObject
{
    public function __construct(
        public Decimal $targetBasisBps,
        public Decimal $maximumAdverseBasisMoveBps,
        public int $maximumHoldingSeconds,
        public Decimal $minimumRemainingExpectedPnl,
        public bool $exitOnFundingSignReversal=true,
        public bool $exitOnDataDegradation=true,
        public bool $exitOnRiskBreach=true,
    ){
        if($maximumAdverseBasisMoveBps->isNegative()||$maximumHoldingSeconds<1){
            throw new InvalidArgumentException('Invalid relative-value exit policy.');
        }
    }
}
