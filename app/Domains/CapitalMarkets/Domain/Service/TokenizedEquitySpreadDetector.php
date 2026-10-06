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
        if($this->crossVenueObservationIssues($a,$b,$config,$now,$ttlMs)!==[])return [];

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
        if($this->referenceObservationIssues($reference,$tokenized,$config,$now,$ttlMs,$tokenQuoteToReferenceQuote)!==[])return [];

        $referenceTimestamp=$reference->sourceTimestamp??$reference->updatedAt;
        $referenceQuoteAsset=$reference->currentQuote->askPrice->quoteAsset;
        $tokenQuoteAsset=$tokenized->bestQuote->bidPrice->quoteAsset;
        $conversion=Decimal::fromString('1');
        $conversionEvidence=null;
        if(!$referenceQuoteAsset->equals($tokenQuoteAsset)){
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

    /** @return list<string> */
    public function crossVenueObservationIssues(
        MarketState $a,
        MarketState $b,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs=1000,
    ):array{
        $issues=[];
        if($ttlMs<$config->minimumOpportunityTtlMs)$issues[]='TTL_BELOW_MINIMUM';
        foreach($this->tradingStateIssues($a,$config,$now,'A') as $issue)$issues[]=$issue;
        foreach($this->tradingStateIssues($b,$config,$now,'B') as $issue)$issues[]=$issue;
        if($issues!==[])return array_values(array_unique($issues));

        if($this->skewMs($a->sourceTimestamp,$b->sourceTimestamp)>$config->maximumSnapshotSkewMs){
            $issues[]='SNAPSHOT_SKEW_EXCEEDED';
        }
        if(!$a->bestQuote->askPrice->quoteAsset->equals($b->bestQuote->bidPrice->quoteAsset)){
            $issues[]='QUOTE_ASSET_NOT_COMPARABLE';
        }
        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    public function referenceObservationIssues(
        ReferenceMarketState $reference,
        MarketState $tokenized,
        SpreadDetectorConfig $config,
        DateTimeImmutable $now,
        int $ttlMs=1000,
        ?ConversionRate $tokenQuoteToReferenceQuote=null,
    ):array{
        $issues=[];
        if($ttlMs<$config->minimumOpportunityTtlMs)$issues[]='TTL_BELOW_MINIMUM';
        if(!$reference->quality->status->isUsableForDecision())$issues[]='REFERENCE_UNTRUSTED';
        if($reference->quality->score<$config->minimumDataQuality)$issues[]='REFERENCE_QUALITY_BELOW_MINIMUM';
        if($reference->currentQuote===null)$issues[]='REFERENCE_QUOTE_UNAVAILABLE';
        foreach($this->tradingStateIssues($tokenized,$config,$now,'TOKEN') as $issue)$issues[]=$issue;
        if($issues!==[])return array_values(array_unique($issues));

        $referenceTimestamp=$reference->sourceTimestamp??$reference->updatedAt;
        if($this->ageMs($referenceTimestamp,$now)>$config->maximumSnapshotAgeMs)$issues[]='REFERENCE_STALE';
        if($this->skewMs($referenceTimestamp,$tokenized->sourceTimestamp)>$config->maximumSnapshotSkewMs){
            $issues[]='SNAPSHOT_SKEW_EXCEEDED';
        }

        $referenceQuoteAsset=$reference->currentQuote->askPrice->quoteAsset;
        $tokenQuoteAsset=$tokenized->bestQuote->bidPrice->quoteAsset;
        if(!$referenceQuoteAsset->equals($tokenQuoteAsset)){
            if($tokenQuoteToReferenceQuote===null){
                $issues[]='QUOTE_CONVERSION_UNAVAILABLE';
            }elseif(!$tokenQuoteToReferenceQuote->usable()){
                $issues[]='QUOTE_CONVERSION_UNTRUSTED';
            }elseif(
                !$tokenQuoteToReferenceQuote->sourceAsset->equals($tokenQuoteAsset)
                ||!$tokenQuoteToReferenceQuote->targetAsset->equals($referenceQuoteAsset)
            ){
                $issues[]='QUOTE_CONVERSION_PAIR_MISMATCH';
            }elseif($this->ageMs($tokenQuoteToReferenceQuote->timestamp,$now)>$config->maximumSnapshotAgeMs){
                $issues[]='QUOTE_CONVERSION_STALE';
            }
        }
        return array_values(array_unique($issues));
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

    /** @return list<string> */
    private function tradingStateIssues(
        MarketState $state,SpreadDetectorConfig $config,DateTimeImmutable $now,string $label
    ):array{
        $issues=[];
        if(!$state->quality->status->isUsableForDecision())$issues[]=$label.'_UNTRUSTED';
        if($state->quality->score<$config->minimumDataQuality)$issues[]=$label.'_QUALITY_BELOW_MINIMUM';
        if($state->marketStatus!==MarketStatus::Open)$issues[]=$label.'_MARKET_NOT_OPEN';
        if($state->bestQuote===null)$issues[]=$label.'_QUOTE_UNAVAILABLE';
        if($this->ageMs($state->sourceTimestamp,$now)>$config->maximumSnapshotAgeMs)$issues[]=$label.'_STALE';
        return $issues;
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
