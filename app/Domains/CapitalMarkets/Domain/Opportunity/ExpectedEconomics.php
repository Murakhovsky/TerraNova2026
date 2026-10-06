<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Opportunity;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExpectedEconomics extends ValueObject
{
    public Decimal $expectedNetPnl;

    public function __construct(
        public Decimal $expectedBasisPnlAttribution,
        public Decimal $expectedFundingPnl,
        public Decimal $expectedPriceNeutralizationPnl,
        public Decimal $fees,
        public Decimal $slippage,
        public Decimal $borrow,
        public Decimal $networkCosts,
        public Decimal $riskAllowance,
        public Decimal $capitalRequired,
        public int $holdingHorizonSeconds,
    ){
        if(!$capitalRequired->isPositive()||$holdingHorizonSeconds<1)throw new InvalidArgumentException('Expected economics requires positive capital and horizon.');
        foreach([$fees,$slippage,$borrow,$networkCosts,$riskAllowance] as $cost){
            if($cost->isNegative())throw new InvalidArgumentException('Expected costs cannot be negative.');
        }
        // Basis is attribution of leg price P&L, not an additional cashflow. Do not double count it.
        $gross=DecimalMath::add($expectedPriceNeutralizationPnl,$expectedFundingPnl);
        $cost=Decimal::fromString('0');
        foreach([$fees,$slippage,$borrow,$networkCosts,$riskAllowance] as $part)$cost=DecimalMath::add($cost,$part);
        $this->expectedNetPnl=DecimalMath::subtract($gross,$cost);
    }

    public function basisAttributionIsNonAdditive():bool{return true;}
}
