<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Portfolio;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class HedgeLeg extends ValueObject
{
    public function __construct(
        public string $instrumentId,
        public string $venueId,
        public PositionSide $side,
        public Decimal $quantity,
        public Decimal $underlyingPerUnit,
        public Decimal $contractMultiplier,
    ){
        if($instrumentId===''||$venueId===''||!$quantity->isPositive()||!$underlyingPerUnit->isPositive()||!$contractMultiplier->isPositive()){
            throw new InvalidArgumentException('Invalid hedge leg.');
        }
    }

    public function underlyingExposure():Decimal
    {
        $exposure=DecimalMath::multiply(DecimalMath::multiply($this->quantity,$this->underlyingPerUnit),$this->contractMultiplier);
        return $this->side===PositionSide::Long?$exposure:DecimalMath::negate($exposure);
    }
}
