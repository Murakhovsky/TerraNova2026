<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final readonly class StrategyScorecard
{
    /** @param array<string,float|int> $dimensions @param array<string,float|int> $weights */
    public function __construct(
        public string $strategyVersionId,
        public array $dimensions,
        public array $weights,
        public int $compositeScore,
        public string $weightVersion,
    ){
        $required=['profitability','consistency','risk','execution_quality','capital_efficiency','capacity','robustness','data_confidence','operational_complexity'];
        foreach($required as $key){
            if(!array_key_exists($key,$dimensions)){
                throw new InvalidArgumentException('Missing scorecard dimension: '.$key);
            }
        }
        if($compositeScore<0||$compositeScore>100){
            throw new InvalidArgumentException('Composite score must be 0..100.');
        }
    }
}
