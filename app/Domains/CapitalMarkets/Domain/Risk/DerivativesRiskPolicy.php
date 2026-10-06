<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Risk;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class DerivativesRiskPolicy extends ValueObject
{
    public function __construct(
        public Decimal $maximumAdverseBasisMoveBps,
        public Decimal $minimumLiquidationDistance,
        public int $maximumUnhedgedTimeMs,
        public Decimal $maximumLeverage,
        public Decimal $maximumNetDelta,
    ){
        if($maximumAdverseBasisMoveBps->isNegative()||$minimumLiquidationDistance->isNegative()||$maximumUnhedgedTimeMs<0||!$maximumLeverage->isPositive()||$maximumNetDelta->isNegative()){
            throw new InvalidArgumentException('Invalid derivatives risk policy.');
        }
    }
}
