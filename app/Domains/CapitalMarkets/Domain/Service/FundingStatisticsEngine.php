<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class FundingStatisticsEngine
{
    /** @param list<FundingRateObservation> $observations @return array<string,mixed> */
    public function summarize(array $observations):array
    {
        if($observations===[])return [
            'count'=>0,'mean'=>'0','median'=>'0','standard_deviation'=>'0',
            'positive_rate_share'=>'0','negative_rate_share'=>'0','rate_persistence'=>'0','sign_change_frequency'=>'0',
        ];
        foreach($observations as $o)if(!$o instanceof FundingRateObservation)throw new InvalidArgumentException('Funding statistics require typed observations.');

        usort($observations,static fn($a,$b)=>$a->observationTimestamp<=>$b->observationTimestamp);
        $rates=array_map(static fn(FundingRateObservation $o):Decimal=>$o->rate,$observations);
        $count=count($rates);$sum=Decimal::fromString('0');$positive=0;$negative=0;$sameSignTransitions=0;$signChanges=0;
        foreach($rates as $i=>$rate){
            $sum=DecimalMath::add($sum,$rate);
            if($rate->isPositive())$positive++; elseif($rate->isNegative())$negative++;
            if($i>0){
                $previous=$rates[$i-1];
                $previousSign=$previous->isPositive()?1:($previous->isNegative()?-1:0);
                $currentSign=$rate->isPositive()?1:($rate->isNegative()?-1:0);
                if($previousSign!==0&&$currentSign!==0){
                    if($previousSign===$currentSign)$sameSignTransitions++; else $signChanges++;
                }
            }
        }
        $mean=DecimalMath::divide($sum,Decimal::fromString((string)$count),18);
        $sorted=$rates;usort($sorted,static fn(Decimal $a,Decimal $b):int=>$a->compareTo($b));
        $median=$count%2===1
            ? $sorted[intdiv($count,2)]
            : DecimalMath::midpoint($sorted[intdiv($count,2)-1],$sorted[intdiv($count,2)],18);

        $varianceSum=Decimal::fromString('0');
        foreach($rates as $rate){
            $d=DecimalMath::subtract($rate,$mean);
            $varianceSum=DecimalMath::add($varianceSum,DecimalMath::multiply($d,$d));
        }
        $variance=DecimalMath::divide($varianceSum,Decimal::fromString((string)$count),24);
        $transitions=max(0,$count-1);

        return [
            'count'=>$count,'mean'=>$mean->value(),'median'=>$median->value(),
            'standard_deviation'=>$this->sqrt($variance,18)->value(),
            'positive_rate_share'=>DecimalMath::divide(Decimal::fromString((string)$positive),Decimal::fromString((string)$count),8)->value(),
            'negative_rate_share'=>DecimalMath::divide(Decimal::fromString((string)$negative),Decimal::fromString((string)$count),8)->value(),
            'rate_persistence'=>$transitions===0?'0':DecimalMath::divide(Decimal::fromString((string)$sameSignTransitions),Decimal::fromString((string)$transitions),8)->value(),
            'sign_change_frequency'=>$transitions===0?'0':DecimalMath::divide(Decimal::fromString((string)$signChanges),Decimal::fromString((string)$transitions),8)->value(),
        ];
    }

    private function sqrt(Decimal $value,int $scale):Decimal
    {
        if($value->isNegative())throw new InvalidArgumentException('Square root cannot accept negative value.');
        if($value->isZero())return $value;
        $x=Decimal::fromString('1');
        if($value->compareTo(Decimal::fromString('1'))>0)$x=$value;
        for($i=0;$i<40;$i++){
            $x=DecimalMath::divide(
                DecimalMath::add($x,DecimalMath::divide($value,$x,$scale+6)),
                Decimal::fromString('2'),$scale+6
            );
        }
        return DecimalMath::divide($x,Decimal::fromString('1'),$scale);
    }
}
