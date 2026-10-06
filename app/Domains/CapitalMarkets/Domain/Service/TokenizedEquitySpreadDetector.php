<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadCandidate;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDirection;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class TokenizedEquitySpreadDetector
{
    /** @return list<SpreadCandidate> */
    public function detect(
        HypothesisCode $hypothesis,string $marketPairId,MarketState $a,MarketState $b,
        SpreadDetectorConfig $config,DateTimeImmutable $now,int $ttlMs=1000
    ):array{
        if($ttlMs<$config->minimumOpportunityTtlMs)return [];
        if(!$a->quality->status->isUsableForDecision()||!$b->quality->status->isUsableForDecision())return [];
        if($a->quality->score<$config->minimumDataQuality||$b->quality->score<$config->minimumDataQuality)return [];
        if($a->marketStatus!==MarketStatus::Open||$b->marketStatus!==MarketStatus::Open)return [];
        if($a->bestQuote===null||$b->bestQuote===null)return [];
        $ageA=max(0,(int)round(((float)$now->format('U.u')-(float)$a->sourceTimestamp->format('U.u'))*1000));
        $ageB=max(0,(int)round(((float)$now->format('U.u')-(float)$b->sourceTimestamp->format('U.u'))*1000));
        if($ageA>$config->maximumSnapshotAgeMs||$ageB>$config->maximumSnapshotAgeMs)return [];
        $skew=(int)round(abs((float)$a->sourceTimestamp->format('U.u')-(float)$b->sourceTimestamp->format('U.u'))*1000);
        if($skew>$config->maximumSnapshotSkewMs)return [];
        $out=[];
        $this->candidate($out,$hypothesis,$marketPairId,$a,$b,$a->bestQuote->askPrice->value,$b->bestQuote->bidPrice->value,
            $a->bestQuote->askQuantity->value,$b->bestQuote->bidQuantity->value,SpreadDirection::BuyA_SellB,$config,$now,$ttlMs);
        $this->candidate($out,$hypothesis,$marketPairId,$b,$a,$b->bestQuote->askPrice->value,$a->bestQuote->bidPrice->value,
            $b->bestQuote->askQuantity->value,$a->bestQuote->bidQuantity->value,SpreadDirection::BuyB_SellA,$config,$now,$ttlMs);
        return $out;
    }
    private function candidate(array &$out,HypothesisCode $h,string $pair,MarketState $buy,MarketState $sell,Decimal $buyPrice,Decimal $sellPrice,Decimal $buyQty,Decimal $sellQty,SpreadDirection $direction,SpreadDetectorConfig $config,DateTimeImmutable $now,int $ttlMs):void
    {
        $spread=DecimalMath::subtract($sellPrice,$buyPrice);
        if(!$spread->isPositive())return;
        $bps=DecimalMath::basisPoints($spread,$buyPrice,6);
        if($bps->compareTo($config->minimumGrossEdgeBps)<0)return;
        $qty=$buyQty->compareTo($sellQty)<=0?$buyQty:$sellQty;
        if(!$qty->isPositive())return;
        $capacity=DecimalMath::multiply($buyPrice,$qty);
        $out[]=new SpreadCandidate(
            'cm_candidate_'.hash('sha256',$pair.'|'.$direction->value.'|'.$now->format('U.u')),
            $h,$pair,$direction,$now,$now->modify('+'.$ttlMs.' milliseconds'),
            $buy->venueId->value(),$sell->venueId->value(),$buy->instrumentId->value(),$sell->instrumentId->value(),
            $buyPrice,$sellPrice,$qty,$spread,$bps,$capacity,min($buy->quality->score,$sell->quality->score),
            ['buy_state_version'=>$buy->stateVersion,'sell_state_version'=>$sell->stateVersion]
        );
    }
}
