<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Performance;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Kernel\Shared\Domain\ValueObject;

final readonly class RelativeValuePerformance extends ValueObject
{
    public Decimal $netPnl;

    public function __construct(
        public Decimal $spotPricePnl,
        public Decimal $derivativePricePnl,
        public Decimal $fundingPnl,
        public Decimal $tradingFees,
        public Decimal $borrowCost,
        public Decimal $networkCosts,
        public Decimal $basisAttribution,
    ){
        $gross=DecimalMath::add(
            DecimalMath::add($spotPricePnl,$derivativePricePnl),
            $fundingPnl
        );
        $costs=DecimalMath::add(
            DecimalMath::add($tradingFees,$borrowCost),
            $networkCosts
        );
        $this->netPnl=DecimalMath::subtract($gross,$costs);
    }

    public function basisIsAttributionOnly():bool{return true;}
}
