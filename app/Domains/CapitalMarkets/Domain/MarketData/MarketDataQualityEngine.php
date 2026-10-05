<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

final class MarketDataQualityEngine
{
    public function assess(
        CanonicalMarketEvent $event,
        MarketHealthPolicy $policy,
        MarketConnectionState $connectionState,
        MarketClock $clock,
        array $additionalFlags=[],
    ):MarketDataQualityAssessment{
        $now=$clock->now();
        $age=MarketTime::diffMilliseconds($now,$event->sourceTimestamp);
        $ingestion=MarketTime::diffMilliseconds($event->receivedTimestamp,$event->sourceTimestamp);
        $processing=MarketTime::diffMilliseconds($event->normalizedTimestamp,$event->receivedTimestamp);
        $flags=[];

        foreach([...$event->qualityFlags,...$additionalFlags] as $flag)if($flag instanceof MarketQualityFlag)$flags[$flag->value]=$flag;
        if(!$clock->isReliable())$flags[MarketQualityFlag::ClockUncertain->value]=MarketQualityFlag::ClockUncertain;
        if($age<-$policy->maxFutureSkewMs)$flags[MarketQualityFlag::ClockAnomaly->value]=MarketQualityFlag::ClockAnomaly;
        if($age>$policy->maxAgeFor($event->eventType))$flags[MarketQualityFlag::Stale->value]=MarketQualityFlag::Stale;
        if($processing>$policy->maxProcessingLatencyMs)$flags[MarketQualityFlag::Late->value]=MarketQualityFlag::Late;
        if($connectionState!==MarketConnectionState::Active)$flags[MarketQualityFlag::SourceDegraded->value]=MarketQualityFlag::SourceDegraded;
        if($event->payload instanceof MarketQuote&&$event->payload->isCrossed())$flags[MarketQualityFlag::CrossedMarket->value]=MarketQualityFlag::CrossedMarket;

        $critical=[
            MarketQualityFlag::ClockAnomaly->value,MarketQualityFlag::SequenceGap->value,
            MarketQualityFlag::OrderBookInvalid->value,MarketQualityFlag::AbnormalPrice->value,
            MarketQualityFlag::CrossedMarket->value,MarketQualityFlag::UnknownInstrument->value,
        ];
        $hasCritical=false;
        foreach($critical as $key)if(isset($flags[$key])){$hasCritical=true;break;}

        if($hasCritical){
            $quality=MarketQualityStatus::Untrusted;$trust=MarketTrustStatus::Untrusted;
        }elseif(isset($flags[MarketQualityFlag::Stale->value])){
            $quality=MarketQualityStatus::Stale;$trust=MarketTrustStatus::Stale;
        }elseif($flags!==[]){
            $quality=MarketQualityStatus::Degraded;$trust=MarketTrustStatus::Degraded;
        }else{
            $quality=MarketQualityStatus::Trusted;$trust=MarketTrustStatus::Trusted;
        }

        $score=max(0,100-count($flags)*12-($hasCritical?20:0));
        return new MarketDataQualityAssessment($quality,$trust,array_values($flags),$score,$age,$ingestion,$processing);
    }
}
