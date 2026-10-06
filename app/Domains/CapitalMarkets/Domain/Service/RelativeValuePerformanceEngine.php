<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Performance\RelativeValuePerformance;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class RelativeValuePerformanceEngine
{
    /**
     * @param list<Position> $positions
     * @param list<Decimal> $fundingCashflows
     */
    public function calculate(
        array $positions,
        array $fundingCashflows,
        Decimal $borrowCost,
        Decimal $networkCosts,
        Decimal $basisAttribution,
    ):RelativeValuePerformance{
        $spotPnl=Decimal::fromString('0');
        $derivativePnl=Decimal::fromString('0');
        $fees=Decimal::fromString('0');
        foreach($positions as $position){
            if(!$position instanceof Position)throw new InvalidArgumentException('Performance positions must be typed.');
            $fees=DecimalMath::add($fees,$position->fees);
            $pnl=DecimalMath::add($position->realizedPnl,$position->unrealizedPnl());
            if($position->contractMultiplier===null)$spotPnl=DecimalMath::add($spotPnl,$pnl);
            else $derivativePnl=DecimalMath::add($derivativePnl,$pnl);
        }
        $funding=Decimal::fromString('0');
        foreach($fundingCashflows as $cashflow){
            if(!$cashflow instanceof Decimal)throw new InvalidArgumentException('Funding cashflows must be Decimal.');
            $funding=DecimalMath::add($funding,$cashflow);
        }
        return new RelativeValuePerformance(
            $spotPnl,$derivativePnl,$funding,$fees,$borrowCost,$networkCosts,$basisAttribution
        );
    }
}
