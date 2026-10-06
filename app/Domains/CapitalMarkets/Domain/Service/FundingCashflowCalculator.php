<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;

final class FundingCashflowCalculator
{
    public function calculate(FundingRateObservation $funding,Decimal $positionNotional,PositionSide $side):Decimal
    {
        if(!$funding->valid())throw new DomainException('FUNDING_DATA_INVALID');
        if($positionNotional->isNegative())throw new DomainException('FUNDING_NOTIONAL_INVALID');
        $cashflow=DecimalMath::multiply($positionNotional,$funding->rate);
        return $side===PositionSide::Short?$cashflow:DecimalMath::negate($cashflow);
    }
}
