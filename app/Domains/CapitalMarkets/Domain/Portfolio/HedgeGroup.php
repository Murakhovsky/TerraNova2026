<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Portfolio;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class HedgeGroup extends ValueObject
{
    /** @param list<HedgeLeg> $legs */
    public function __construct(
        public string $id,
        public string $strategyVersion,
        public array $legs,
        public Decimal $targetDelta,
        public Decimal $allowedTolerance,
        public HedgeState $state,
    ){
        if($id===''||$strategyVersion===''||count($legs)<2||$allowedTolerance->isNegative())throw new InvalidArgumentException('Invalid hedge group.');
        foreach($legs as $leg)if(!$leg instanceof HedgeLeg)throw new InvalidArgumentException('Hedge group legs must be typed.');
    }

    public function netUnderlyingExposure():Decimal
    {
        $net=Decimal::fromString('0');
        foreach($this->legs as $leg)$net=DecimalMath::add($net,$leg->underlyingExposure());
        return $net;
    }

    public function drift():Decimal{return DecimalMath::subtract($this->netUnderlyingExposure(),$this->targetDelta);}
}
