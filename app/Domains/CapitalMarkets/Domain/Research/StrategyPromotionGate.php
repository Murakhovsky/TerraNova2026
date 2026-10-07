<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;

final class StrategyPromotionGate
{
    public function evaluate(string $from,string $to,array $actual,array $policy):array
    {
        $allowed=[
            'RESEARCH:BACKTEST','BACKTEST:OOS','OOS:PAPER','PAPER:LIMITED_LIVE',
            'LIMITED_LIVE:VALIDATED','VALIDATED:SCALE'
        ];
        if(!in_array($from.':'.$to,$allowed,true)){
            throw new InvalidArgumentException('Unsupported promotion transition.');
        }

        if($policy===[])return ['status'=>'FAILED','criteria'=>[],'reason'=>'PROMOTION_POLICY_REQUIRED'];
        if($to==='LIMITED_LIVE' && (($policy['manual_review_required']??false)!==true)){
            return ['status'=>'FAILED','criteria'=>[],'reason'=>'LIMITED_LIVE_REQUIRES_MANUAL_REVIEW'];
        }

        $criteria=[];
        $manual=false;
        foreach($policy as $key=>$threshold){
            if($key==='policy_version')continue;
            if($key==='manual_review_required'){
                $manual=(bool)$threshold;
                continue;
            }
            $value=$actual[$key]??null;
            $passed=$this->passes($value,$threshold);
            $criteria[(string)$key]=['actual'=>$value,'threshold'=>$threshold,'passed'=>$passed];
        }

        if($criteria===[])return ['status'=>'FAILED','criteria'=>[],'reason'=>'PROMOTION_CRITERIA_REQUIRED'];
        if($manual)return ['status'=>'MANUAL_REVIEW_REQUIRED','criteria'=>$criteria];
        foreach($criteria as $criterion){
            if(!$criterion['passed'])return ['status'=>'FAILED','criteria'=>$criteria];
        }
        return ['status'=>'PASSED','criteria'=>$criteria];
    }

    private function passes(mixed $actual,mixed $threshold):bool
    {
        if(is_array($threshold)){
            if(array_key_exists('min',$threshold)){
                if(!is_numeric($actual)||!is_numeric($threshold['min']))return false;
                if(Decimal::fromString((string)$actual)->compareTo(Decimal::fromString((string)$threshold['min']))<0)return false;
            }
            if(array_key_exists('max',$threshold)){
                if(!is_numeric($actual)||!is_numeric($threshold['max']))return false;
                if(Decimal::fromString((string)$actual)->compareTo(Decimal::fromString((string)$threshold['max']))>0)return false;
            }
            if(array_key_exists('equals',$threshold) && $actual!==$threshold['equals'])return false;
            return true;
        }
        return $actual===$threshold;
    }
}
