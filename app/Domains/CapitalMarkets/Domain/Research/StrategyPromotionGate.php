<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final class StrategyPromotionGate
{
    /** @param array<string,mixed> $actual @param array<string,mixed> $policy
     *  @return array{status:string,criteria:array<string,array{actual:mixed,threshold:mixed,passed:bool}>}
     */
    public function evaluate(string $from,string $to,array $actual,array $policy):array
    {
        $allowed=[
            'RESEARCH:BACKTEST','BACKTEST:OOS','OOS:PAPER','PAPER:LIMITED_LIVE',
            'LIMITED_LIVE:VALIDATED','VALIDATED:SCALE'
        ];
        if(!in_array($from.':'.$to,$allowed,true)){
            throw new InvalidArgumentException('Unsupported promotion transition.');
        }

        $criteria=[];
        $manual=false;
        foreach($policy as $key=>$threshold){
            if($key==='manual_review_required'){
                $manual=(bool)$threshold;
                continue;
            }
            $value=$actual[$key]??null;
            $passed=$this->passes($value,$threshold);
            $criteria[(string)$key]=['actual'=>$value,'threshold'=>$threshold,'passed'=>$passed];
        }

        if($manual){
            return ['status'=>'MANUAL_REVIEW_REQUIRED','criteria'=>$criteria];
        }
        foreach($criteria as $criterion){
            if(!$criterion['passed']){
                return ['status'=>'FAILED','criteria'=>$criteria];
            }
        }
        return ['status'=>'PASSED','criteria'=>$criteria];
    }

    private function passes(mixed $actual,mixed $threshold):bool
    {
        if(is_array($threshold)){
            if(array_key_exists('min',$threshold) && (!is_numeric($actual)||(float)$actual<(float)$threshold['min']))return false;
            if(array_key_exists('max',$threshold) && (!is_numeric($actual)||(float)$actual>(float)$threshold['max']))return false;
            if(array_key_exists('equals',$threshold) && $actual!==$threshold['equals'])return false;
            return true;
        }
        return $actual===$threshold;
    }
}
