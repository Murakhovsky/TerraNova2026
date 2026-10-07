<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class StrategyScorecardEngine
{
    private const DIMENSIONS=[
        'profitability','consistency','risk','execution_quality','capital_efficiency',
        'capacity','robustness','data_confidence','operational_complexity'
    ];

    public function calculate(string $strategyVersionId,array $dimensions,array $weights,string $weightVersion):StrategyScorecard
    {
        $weightTotal=Decimal::fromString('0');
        $weighted=Decimal::fromString('0');

        foreach(self::DIMENSIONS as $dimension){
            if(!array_key_exists($dimension,$dimensions))throw new InvalidArgumentException('Missing score dimension: '.$dimension);
            if(!is_numeric($dimensions[$dimension]))throw new InvalidArgumentException('Score dimension must be numeric: '.$dimension);
            $score=Decimal::fromString((string)$dimensions[$dimension]);
            if($score->compareTo(Decimal::fromString('0'))<0)$score=Decimal::fromString('0');
            if($score->compareTo(Decimal::fromString('100'))>0)$score=Decimal::fromString('100');
            $dimensions[$dimension]=$score->value();

            $rawWeight=$weights[$dimension]??0;
            if(!is_numeric($rawWeight))throw new InvalidArgumentException('Scorecard weight must be numeric: '.$dimension);
            $weight=Decimal::fromString((string)$rawWeight);
            if($weight->isNegative())throw new InvalidArgumentException('Scorecard weights cannot be negative.');

            $weightTotal=DecimalMath::add($weightTotal,$weight);
            $weighted=DecimalMath::add($weighted,DecimalMath::multiply($score,$weight));
        }

        if(!$weightTotal->isPositive())throw new InvalidArgumentException('Scorecard weights must have positive total.');
        $score=DecimalMath::divide($weighted,$weightTotal,0);
        return new StrategyScorecard($strategyVersionId,$dimensions,$weights,max(0,min(100,(int)$score->value())),$weightVersion);
    }
}
