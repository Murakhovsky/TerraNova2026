<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketSequencePolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final readonly class MarketDataQualityEngine
{
    public function assess(
        CanonicalMarketEvent $event,
        ?MarketState $previous,
        MarketSourceHealth $sourceHealth,
        DateTimeImmutable $now,
        MarketDataQualityPolicy $policy,
        ?Decimal $convertedReferencePrice=null,
    ):MarketDataQualityAssessment{
        $flags=[];
        foreach($event->qualityFlags as $flag)$flags[$flag->value]=$flag;

        $ingestionLatency=$event->timestamps->ingestionLatencyMilliseconds();
        $processingLatency=$event->timestamps->processingLatencyMilliseconds();
        $age=$event->timestamps->ageMilliseconds($now);

        if($age>$policy->maximumAgeFor($event->eventType()))$flags[MarketQualityFlag::Stale->value]=MarketQualityFlag::Stale;
        if($processingLatency>$policy->maximumProcessingLatencyMilliseconds)$flags[MarketQualityFlag::Late->value]=MarketQualityFlag::Late;
        if(!$sourceHealth->clockReliable||$ingestionLatency<-$policy->maximumClockDriftMilliseconds){
            $flags[MarketQualityFlag::ClockUncertain->value]=MarketQualityFlag::ClockUncertain;
        }
        if($sourceHealth->connectionState!==MarketConnectionState::Active){
            $flags[MarketQualityFlag::SourceDegraded->value]=MarketQualityFlag::SourceDegraded;
        }

        if($event->observation instanceof MarketQuote){
            if($event->observation->isCrossed())$flags[MarketQualityFlag::CrossedMarket->value]=MarketQualityFlag::CrossedMarket;
            if(!$event->observation->isCrossed()){
                $spread=$event->observation->spreadBps();
                if($spread->compareTo(Decimal::fromString((string)$policy->maximumSpreadBps))>0){
                    $flags[MarketQualityFlag::AbnormalPrice->value]=MarketQualityFlag::AbnormalPrice;
                }
            }
        }

        if($event->observation instanceof MarketOrderBook
            &&$event->eventType()===MarketEventType::OrderBookDelta
            &&($previous===null||$previous->orderBook===null)){
            $flags[MarketQualityFlag::OrderBookInvalid->value]=MarketQualityFlag::OrderBookInvalid;
        }

        if($previous!==null){
            if($previous->lastEventFingerprint===$event->fingerprint()){
                $flags[MarketQualityFlag::Duplicate->value]=MarketQualityFlag::Duplicate;
            }
            if($event->timestamps->sourceTimestamp<$previous->sourceTimestamp){
                $flags[MarketQualityFlag::OutOfOrder->value]=MarketQualityFlag::OutOfOrder;
            }
            $this->assessSequence($event,$previous,$policy,$flags);

            $currentPrice=$this->eventPrice($event);
            $previousPrice=$this->statePrice($previous);
            if($currentPrice!==null&&$previousPrice!==null&&!$previousPrice->isZero()){
                $jump=DecimalMath::basisPoints(DecimalMath::abs(DecimalMath::subtract($currentPrice,$previousPrice)),$previousPrice,6);
                if($jump->compareTo(Decimal::fromString((string)$policy->maximumJumpBps))>0){
                    $flags[MarketQualityFlag::AbnormalPrice->value]=MarketQualityFlag::AbnormalPrice;
                }
            }
        }

        $referenceDeviation=null;
        $currentPrice=$this->eventPrice($event);
        if($convertedReferencePrice!==null&&$currentPrice!==null&&!$convertedReferencePrice->isZero()){
            $referenceDeviation=DecimalMath::basisPoints(
                DecimalMath::abs(DecimalMath::subtract($currentPrice,$convertedReferencePrice)),
                $convertedReferencePrice,
                6,
            );
            if($referenceDeviation->compareTo(Decimal::fromString((string)$policy->maximumReferenceDeviationBps))>0){
                $flags[MarketQualityFlag::ReferenceMismatch->value]=MarketQualityFlag::ReferenceMismatch;
            }
        }

        $flags=array_values($flags);
        $status=$this->status($flags,$sourceHealth->connectionState);
        $score=$this->score($flags,$status);

        return new MarketDataQualityAssessment(
            $status,$score,$flags,$ingestionLatency,$processingLatency,$age,$referenceDeviation
        );
    }

    /**
     * @param array<string,MarketQualityFlag> $flags
     */
    private function assessSequence(
        CanonicalMarketEvent $event,
        MarketState $previous,
        MarketDataQualityPolicy $policy,
        array &$flags,
    ):void{
        if($event->sequence===null||$previous->lastSequence===null)return;
        if(!ctype_digit($event->sequence)||!ctype_digit($previous->lastSequence))return;

        $sequencePolicy=$policy->sequencePolicyFor($event->eventType());
        if($sequencePolicy===MarketSequencePolicy::None)return;

        $cmp=$this->compareUnsigned($event->sequence,$previous->lastSequence);
        if($cmp<=0){
            $flags[MarketQualityFlag::OutOfOrder->value]=MarketQualityFlag::OutOfOrder;
            return;
        }
        if($sequencePolicy===MarketSequencePolicy::Contiguous
            &&$event->sequence!==$this->incrementUnsigned($previous->lastSequence)){
            $flags[MarketQualityFlag::SequenceGap->value]=MarketQualityFlag::SequenceGap;
            if($event->eventType()===MarketEventType::OrderBookDelta){
                $flags[MarketQualityFlag::OrderBookInvalid->value]=MarketQualityFlag::OrderBookInvalid;
            }
        }
    }

    /** @param list<MarketQualityFlag> $flags */
    private function status(array $flags,MarketConnectionState $connection):MarketTrustStatus
    {
        if(in_array($connection,[MarketConnectionState::Disabled,MarketConnectionState::Failed,MarketConnectionState::Disconnected],true)){
            return MarketTrustStatus::Unavailable;
        }

        $values=array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$flags);
        foreach([
            MarketQualityFlag::ClockUncertain,
            MarketQualityFlag::CrossedMarket,
            MarketQualityFlag::SequenceGap,
            MarketQualityFlag::OrderBookInvalid,
            MarketQualityFlag::UnknownInstrument,
        ] as $critical){
            if(in_array($critical->value,$values,true))return MarketTrustStatus::Untrusted;
        }
        if(in_array(MarketQualityFlag::Stale->value,$values,true))return MarketTrustStatus::Stale;
        if($flags!==[])return MarketTrustStatus::Degraded;
        return MarketTrustStatus::Trusted;
    }

    /** @param list<MarketQualityFlag> $flags */
    private function score(array $flags,MarketTrustStatus $status):int
    {
        if($status===MarketTrustStatus::Unavailable)return 0;
        $score=100;
        $penalties=[
            MarketQualityFlag::Stale->value=>60,
            MarketQualityFlag::ClockUncertain->value=>70,
            MarketQualityFlag::CrossedMarket->value=>60,
            MarketQualityFlag::SequenceGap->value=>70,
            MarketQualityFlag::OrderBookInvalid->value=>70,
            MarketQualityFlag::UnknownInstrument->value=>100,
            MarketQualityFlag::SourceDegraded->value=>30,
            MarketQualityFlag::OutOfOrder->value=>35,
            MarketQualityFlag::Duplicate->value=>5,
            MarketQualityFlag::Late->value=>15,
            MarketQualityFlag::AbnormalPrice->value=>25,
            MarketQualityFlag::ReferenceMismatch->value=>15,
            MarketQualityFlag::PrecisionMismatch->value=>10,
            MarketQualityFlag::Incomplete->value=>20,
        ];
        foreach($flags as $flag)$score-=($penalties[$flag->value]??5);
        return max(0,min(100,$score));
    }

    private function compareUnsigned(string $left,string $right):int
    {
        $left=ltrim($left,'0')?:'0';
        $right=ltrim($right,'0')?:'0';
        $length=strlen($left)<=>strlen($right);
        return $length!==0?$length:(strcmp($left,$right)<=>0);
    }

    private function incrementUnsigned(string $value):string
    {
        $digits=str_split($value);$carry=1;
        for($i=count($digits)-1;$i>=0&&$carry===1;$i--){
            $next=(ord($digits[$i])-48)+1;
            $digits[$i]=chr(48+($next%10));
            $carry=$next>=10?1:0;
        }
        if($carry===1)array_unshift($digits,'1');
        return ltrim(implode('',$digits),'0')?:'0';
    }

    private function eventPrice(CanonicalMarketEvent $event):?Decimal
    {
        return match(true){
            $event->observation instanceof MarketQuote=>$event->observation->midPrice(),
            $event->observation instanceof MarketTrade=>$event->observation->price->value,
            $event->observation instanceof MarketValueObservation
                &&in_array($event->eventType(),[MarketEventType::ReferencePrice,MarketEventType::MarkPrice,MarketEventType::IndexPrice],true)
                =>$event->observation->value,
            default=>null,
        };
    }

    private function statePrice(MarketState $state):?Decimal
    {
        if($state->bestQuote!==null)return $state->bestQuote->midPrice();
        return $state->lastTrade?->price->value;
    }
}
