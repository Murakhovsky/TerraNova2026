<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class FundingRateNormalizer
{
    /**
     * Derived display/comparison metrics assume the observed rate persisted through the requested period.
     * They are not guaranteed yield.
     * @return array{native_rate:string,native_interval_seconds:int,comparable_period_rate:string,daily_equivalent:string,annualized_display_rate:string,assumption:string}
     */
    public function normalize(FundingRateObservation $observation,int $comparablePeriodSeconds=86400):array
    {
        if($comparablePeriodSeconds<60)throw new InvalidArgumentException('Comparable funding period must be >= 60 seconds.');
        $nativeSeconds=Decimal::fromString((string)$observation->fundingIntervalSeconds);
        $periodFactor=DecimalMath::divide(Decimal::fromString((string)$comparablePeriodSeconds),$nativeSeconds,12);
        $dayFactor=DecimalMath::divide(Decimal::fromString('86400'),$nativeSeconds,12);
        $yearFactor=DecimalMath::divide(Decimal::fromString('31536000'),$nativeSeconds,12);
        return [
            'native_rate'=>$observation->rate->value(),
            'native_interval_seconds'=>$observation->fundingIntervalSeconds,
            'comparable_period_rate'=>DecimalMath::multiply($observation->rate,$periodFactor)->value(),
            'daily_equivalent'=>DecimalMath::multiply($observation->rate,$dayFactor)->value(),
            'annualized_display_rate'=>DecimalMath::multiply($observation->rate,$yearFactor)->value(),
            'assumption'=>'OBSERVED_RATE_PERSISTS_UNCHANGED_FOR_DISPLAY_ONLY',
        ];
    }
}
