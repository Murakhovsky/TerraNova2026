<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class ParameterSensitivityEngine
{
    public function analyze(array $points,int $peakRatioThresholdBps=17500):array
    {
        if(count($points)<3)return ['warning'=>null,'robustness'=>0,'points'=>$points];

        usort($points,static fn(array $a,array $b):int=>
            Decimal::fromString((string)$a['parameter'])->compareTo(Decimal::fromString((string)$b['parameter']))
        );
        $scores=array_map(static fn(array $p):Decimal=>Decimal::fromString((string)($p['score']??0)),$points);

        $peak=$scores[0];$peakIndex=0;
        foreach($scores as $index=>$score){
            if($score->compareTo($peak)>0){$peak=$score;$peakIndex=$index;}
        }

        $neighbors=[];
        if($peakIndex>0)$neighbors[]=$scores[$peakIndex-1];
        if($peakIndex<count($scores)-1)$neighbors[]=$scores[$peakIndex+1];
        if($neighbors===[])return ['warning'=>null,'robustness'=>0,'points'=>$points];

        $sum=Decimal::fromString('0');
        foreach($neighbors as $neighbor)$sum=DecimalMath::add($sum,$neighbor);
        $average=DecimalMath::divide($sum,Decimal::fromString((string)count($neighbors)),12);
        if(!$average->isPositive()){
            return ['warning'=>'OVERFIT_RISK','robustness'=>0,'peak_to_neighbor_ratio'=>'INF','points'=>$points];
        }

        $ratio=DecimalMath::divide($peak,$average,12);
        $threshold=DecimalMath::divide(Decimal::fromString((string)$peakRatioThresholdBps),Decimal::fromString('10000'),12);
        $warning=$ratio->compareTo($threshold)>=0?'OVERFIT_RISK':null;
        $robustnessRatio=DecimalMath::divide(Decimal::fromString('100'),$ratio,0);
        $robustness=max(0,min(100,(int)$robustnessRatio->value()));

        return [
            'warning'=>$warning,
            'robustness'=>$robustness,
            'peak_to_neighbor_ratio'=>$ratio->value(),
            'points'=>$points,
        ];
    }
}
