<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadCandidate;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDirection;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class TokenizedEquitySpreadDetector
{
    /** @return list<SpreadCandidate> */
    public function detectCrossVenue(
        string $marketPairId,
        MarketState $a,
        MarketState $b,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs=1000,
    ):array{
        if(!$this->usableTradingState($a,$config,$now)||!$this->usableTradingState($b,$config,$now))return [];
        if($this->skewMs($a->sourceTimestamp,$b->sourceTimestamp)>$config->maximumSnapshotSkewMs)return [];
        if(!$a->bestQuote->askPrice->quoteAsset->equals($b->bestQuote->bidPrice->quoteAsset))return [];

        $out=[];
        $this->candidate(
            $out,HypothesisCode::CrossVenueTokenizedEquityArbitrage,$marketPairId,
            $a->venueId->value(),$b->venueId->value(),$a->instrumentId->value(),$b->instrumentId->value(),
            $a->bestQuote->askPrice->value,$b->bestQuote->bidPrice->value,
            $a->bestQuote->askQuantity->value,$b->bestQuote->bidQuantity->value,
            SpreadDirection::BuyA_SellB,min($a->quality->score,$b->quality->score),
            ['buy_state_version'=>$a->stateVersion,'sell_state_version'=>$b->stateVersion],
            $config,$now,$ttlMs,
        );
        $this->candidate(
            $out,HypothesisCode::CrossVenueTokenizedEquityArbitrage,$marketPairId,
            $b->venueId->value(),$a->venueId->value(),$b->instrumentId->value(),$a->instrumentId->value(),
            $b->bestQuote->askPrice->value,$a->bestQuote->bidPrice->value,
            $b->bestQuote->askQuantity->value,$a->bestQuote->bidQuantity->value,
            SpreadDirection::BuyB_SellA,min($a->quality->score,$b->quality->score),
            ['buy_state_version'=>$b->stateVersion,'sell_state_version'=>$a->stateVersion],
            $config,$now,$ttlMs,
        );
        return $out;
    }

    /** @return list<SpreadCandidate> */
    public function detectReferenceDislocation(
        string $marketPairId,
        ReferenceMarketState $reference,
        MarketState $tokenized,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs=1000,
        ?ConversionRate $tokenQuoteToReferenceQuote=null,
    ):array{
        if($ttlMs<$config->minimumOpportunityTtlMs)return [];
        if(!$reference->quality->status->isUsableForDecision()||$reference->quality->score<$config->minimumDataQuality)return [];
        if(!$this->usableTradingState($tokenized,$config,$now))return [];
        if($reference->currentQuote===null)return [];
        $referenceTimestamp=$reference->sourceTimestamp??$reference->updatedAt;
        if($this->ageMs($referenceTimestamp,$now)>$config->maximumSnapshotAgeMs)return [];
        if($this->skewMs($referenceTimestamp,$tokenized->sourceTimestamp)>$config->maximumSnapshotSkewMs)return [];
        $referenceQuoteAsset=$reference->currentQuote->askPrice->quoteAsset;
        $tokenQuoteAsset=$tokenized->bestQuote->bidPrice->quoteAsset;
        $conversion=Decimal::fromString('1');
        $conversionEvidence=null;
        if(!$referenceQuoteAsset->equals($tokenQuoteAsset)){
            if($tokenQuoteToReferenceQuote===null||!$tokenQuoteToReferenceQuote->usable())return [];
            if(!$tokenQuoteToReferenceQuote->sourceAsset->equals($tokenQuoteAsset)
                ||!$tokenQuoteToReferenceQuote->targetAsset->equals($referenceQuoteAsset))return [];
            if($this->ageMs($tokenQuoteToReferenceQuote->timestamp,$now)>$config->maximumSnapshotAgeMs)return [];
            $conversion=$tokenQuoteToReferenceQuote->rate;
            $conversionEvidence=$tokenQuoteToReferenceQuote->toArray();
        }

        $tokenBid=DecimalMath::multiply($tokenized->bestQuote->bidPrice->value,$conversion);
        $tokenAsk=DecimalMath::multiply($tokenized->bestQuote->askPrice->value,$conversion);

        $out=[];
        $referenceVenue='reference:'.$reference->sourceId->value();
        $quality=min($reference->quality->score,$tokenized->quality->score);

        $this->candidate(
            $out,HypothesisCode::TokenizedEquityDislocation,$marketPairId,
            $tokenized->venueId->value(),$referenceVenue,$tokenized->instrumentId->value(),$reference->instrumentId->value(),
            $tokenAsk,$reference->currentQuote->bidPrice->value,
            $tokenized->bestQuote->askQuantity->value,$reference->currentQuote->bidQuantity->value,
            SpreadDirection::BuyA_SellB,$quality,
            ['token_state_version'=>$tokenized->stateVersion,'reference_state_version'=>$reference->stateVersion,'conversion'=>$conversionEvidence],
            $config,$now,$ttlMs,
        );
        $this->candidate(
            $out,HypothesisCode::TokenizedEquityDislocation,$marketPairId,
            $referenceVenue,$tokenized->venueId->value(),$reference->instrumentId->value(),$tokenized->instrumentId->value(),
            $reference->currentQuote->askPrice->value,$tokenBid,
            $reference->currentQuote->askQuantity->value,$tokenized->bestQuote->bidQuantity->value,
            SpreadDirection::BuyB_SellA,$quality,
            ['token_state_version'=>$tokenized->stateVersion,'reference_state_version'=>$reference->stateVersion,'conversion'=>$conversionEvidence],
            $config,$now,$ttlMs,
        );
        return $out;
    }

    /** @deprecated use detectCrossVenue() for H2 */
    public function detect(
        HypothesisCode $hypothesis,
        string $marketPairId,
        MarketState $a,
        MarketState $b,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs=1000,
    ):array{
        if($hypothesis!==HypothesisCode::CrossVenueTokenizedEquityArbitrage)return [];
        return $this->detectCrossVenue($marketPairId,$a,$b,$config,$now,$ttlMs);
    }

    private function usableTradingState(MarketState $state,SpreadDetectorConfig $config,DateTimeImmutable $now):bool
    {
        if(!$state->quality->status->isUsableForDecision()||$state->quality->score<$config->minimumDataQuality)return false;
        if($state->marketStatus!==MarketStatus::Open||$state->bestQuote===null)return false;
        return $this->ageMs($state->sourceTimestamp,$now)<=$config->maximumSnapshotAgeMs;
    }

    private function ageMs(DateTimeImmutable $source,DateTimeImmutable $now):int
    {
        $delta=$this->epochMicros($now)-$this->epochMicros($source);
        return max(0,intdiv($delta,1000));
    }

    private function skewMs(DateTimeImmutable $a,DateTimeImmutable $b):int
    {
        return intdiv(abs($this->epochMicros($a)-$this->epochMicros($b)),1000);
    }

    private function epochMicros(DateTimeImmutable $value):int
    {
        return ((int)$value->format('U')*1000000)+(int)$value->format('u');
    }

    private function candidate(
        array &$out,
        HypothesisCode $hypothesis,
        string $pair,
        string $buyVenue,
        string $sellVenue,
        string $buyInstrument,
        string $sellInstrument,
        Decimal $buyPrice,
        Decimal $sellPrice,
        Decimal $buyQty,
        Decimal $sellQty,
        SpreadDirection $direction,
        int $quality,
        array $evidence,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs,
    ):void{
        if($ttlMs<$config->minimumOpportunityTtlMs)return;
        $spread=DecimalMath::subtract($sellPrice,$buyPrice);
        if(!$spread->isPositive())return;
        $bps=DecimalMath::basisPoints($spread,$buyPrice,6);
        if($bps->compareTo($config->minimumGrossEdgeBps)<0)return;
        $qty=$buyQty->compareTo($sellQty)<=0?$buyQty:$sellQty;
        if(!$qty->isPositive())return;
        $capacity=DecimalMath::multiply($buyPrice,$qty);
        $out[]=new SpreadCandidate(
            'cm_candidate_'.hash('sha256',$hypothesis->value.'|'.$pair.'|'.$direction->value.'|'.$now->format('U.u')),
            $hypothesis,$pair,$direction,$now,$now->modify('+'.$ttlMs.' milliseconds'),
            $buyVenue,$sellVenue,$buyInstrument,$sellInstrument,
            $buyPrice,$sellPrice,$qty,$spread,$bps,$capacity,$quality,$evidence,
        );
    }
}
