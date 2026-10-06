<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final class StrategyScorecardEngine
{
    private const DIMENSIONS=[
        'profitability','consistency','risk','execution_quality','capital_efficiency',
        'capacity','robustness','data_confidence','operational_complexity'
    ];

    public function calculate(string $strategyVersionId,array $dimensions,array $weights,string $weightVersion):StrategyScorecard
    {
        foreach(self::DIMENSIONS as $dimension){
            if(!array_key_exists($dimension,$dimensions))throw new InvalidArgumentException('Missing score dimension: '.$dimension);
            $dimensions[$dimension]=max(0,min(100,(float)$dimensions[$dimension]));
        }
        $weightTotal=0.0;$weighted=0.0;
        foreach(self::DIMENSIONS as $dimension){
            $weight=(float)($weights[$dimension]??0);
            if($weight<0)throw new InvalidArgumentException('Scorecard weights cannot be negative.');
            $weightTotal+=$weight;
            $weighted+=(float)$dimensions[$dimension]*$weight;
        }
        if($weightTotal<=0)throw new InvalidArgumentException('Scorecard weights must have positive total.');
        $score=(int)round($weighted/$weightTotal);
        return new StrategyScorecard($strategyVersionId,$dimensions,$weights,$score,$weightVersion);
    }
}
