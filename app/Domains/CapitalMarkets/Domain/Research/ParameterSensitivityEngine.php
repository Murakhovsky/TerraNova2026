<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

final class ParameterSensitivityEngine
{
    public function analyze(array $points,float $peakRatioThreshold=1.75):array
    {
        if(count($points)<3)return ['warning'=>null,'robustness'=>0,'points'=>$points];
        usort($points,static fn(array $a,array $b):int=>(float)$a['parameter']<=>(float)$b['parameter']);
        $scores=array_map(static fn(array $p):float=>(float)($p['score']??0),$points);
        $peak=max($scores);
        $peakIndex=array_search($peak,$scores,true);
        $neighbors=[];
        if(is_int($peakIndex)&&$peakIndex>0)$neighbors[]=$scores[$peakIndex-1];
        if(is_int($peakIndex)&&$peakIndex<count($scores)-1)$neighbors[]=$scores[$peakIndex+1];
        $neighborAverage=$neighbors===[]?0.0:array_sum($neighbors)/count($neighbors);
        $ratio=$neighborAverage<=0?INF:$peak/$neighborAverage;
        $warning=$ratio>=$peakRatioThreshold?'OVERFIT_RISK':null;
        $robustness=(int)round(max(0,min(100,100/($ratio===INF?100:$ratio))));
        return ['warning'=>$warning,'robustness'=>$robustness,'peak_to_neighbor_ratio'=>$ratio,'points'=>$points];
    }
}
