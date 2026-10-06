<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use DomainException;

final class SpotPerpetualMarketStateFactory
{
    public function build(MarketState $spot,MarketState $perpetual,bool $relationshipValid=true):SpotPerpetualMarketState
    {
        if($spot->bestQuote===null||$perpetual->bestQuote===null)throw new DomainException('SPOT_PERP_BBO_REQUIRED');
        if($perpetual->fundingRate===null||$perpetual->fundingRate->eventType()!==MarketEventType::FundingRate){
            throw new DomainException('PERPETUAL_FUNDING_REQUIRED');
        }

        $attrs=$perpetual->fundingRate->attributes;
        $interval=(int)($attrs['funding_interval_seconds']??0);
        if($interval<60)throw new DomainException('PERPETUAL_FUNDING_INTERVAL_REQUIRED');

        $next=$this->timestamp($attrs['next_settlement_at']??null);
        $status=FundingRateStatus::tryFrom(strtoupper((string)($attrs['status']??'UNKNOWN')))??FundingRateStatus::Unknown;
        $rateType=FundingRateType::tryFrom(strtoupper((string)($attrs['rate_type']??'VENUE_NATIVE')))??FundingRateType::VenueNative;

        $funding=new FundingRateObservation(
            $perpetual->venueId,$perpetual->instrumentId,$perpetual->fundingRate->value,$rateType,
            $perpetual->sourceTimestamp,$next,$interval,
            $this->optionalDecimal($attrs['cap']??null),$this->optionalDecimal($attrs['floor']??null),
            $perpetual->sourceId->value(),$perpetual->quality->score,$status,
        );

        $mark=$perpetual->markPrice?->value;
        $index=$perpetual->indexPrice?->value;
        $basis=BasisObservation::fromTopOfBook(
            $spot->key(),$perpetual->key(),max($spot->updatedAt,$perpetual->updatedAt),
            $spot->bestQuote->bidPrice->value,$spot->bestQuote->askPrice->value,
            $perpetual->bestQuote->bidPrice->value,$perpetual->bestQuote->askPrice->value,
            $mark,$index,min($spot->quality->score,$perpetual->quality->score),
        );

        return new SpotPerpetualMarketState(
            $spot,$perpetual,$basis,$funding,$mark,$index,$perpetual->openInterest?->value,
            $this->liquidityScore($spot,$perpetual),$relationshipValid,
            min($spot->quality->score,$perpetual->quality->score),max($spot->updatedAt,$perpetual->updatedAt),
        );
    }

    private function timestamp(mixed $value):?DateTimeImmutable
    {
        if($value===null||$value==='')return null;
        if((is_string($value)||is_int($value))&&ctype_digit((string)$value)){
            $raw=(string)$value;
            $seconds=strlen($raw)>10?intdiv((int)$raw,1000):(int)$raw;
            return (new DateTimeImmutable())->setTimestamp($seconds);
        }
        if(is_string($value))return new DateTimeImmutable($value);
        throw new DomainException('INVALID_FUNDING_SETTLEMENT_TIMESTAMP');
    }

    private function optionalDecimal(mixed $value):?Decimal
    {
        if($value===null||$value==='')return null;
        return Decimal::fromString((string)$value);
    }

    private function liquidityScore(MarketState $spot,MarketState $perpetual):int
    {
        $score=min($spot->quality->score,$perpetual->quality->score);
        if($spot->orderBook===null||$perpetual->orderBook===null)$score=min($score,70);
        return max(0,min(100,$score));
    }
}
