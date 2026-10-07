<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;

final class StrategyDemotionPolicy
{
    public function evaluate(array $actual,array $policy):array
    {
        $reasons=[];$criteria=0;
        foreach($policy as $metric=>$rule){
            if(in_array($metric,['action','policy_version'],true))continue;
            if(!is_array($rule))continue;
            $criteria++;
            $value=$actual[$metric]??null;
            if(!is_numeric($value)){
                $reasons[]=$metric.'_MISSING_OR_INVALID';
                continue;
            }
            $actualDecimal=Decimal::fromString((string)$value);
            if(isset($rule['max'])&&is_numeric($rule['max'])
                &&$actualDecimal->compareTo(Decimal::fromString((string)$rule['max']))>0){
                $reasons[]=$metric.'_ABOVE_MAX';
            }
            if(isset($rule['min'])&&is_numeric($rule['min'])
                &&$actualDecimal->compareTo(Decimal::fromString((string)$rule['min']))<0){
                $reasons[]=$metric.'_BELOW_MIN';
            }
        }
        if($criteria===0)return ['action'=>'RESEARCH_REQUIRED','reasons'=>['DEMOTION_CRITERIA_REQUIRED']];
        if($reasons===[])return ['action'=>'NONE','reasons'=>[]];

        $action=(string)($policy['action']??'RESEARCH_REQUIRED');
        if(!in_array($action,['REDUCE','RETURN_TO_PAPER','SUSPEND','REJECT','RESEARCH_REQUIRED'],true)){
            $action='RESEARCH_REQUIRED';
        }
        return ['action'=>$action,'reasons'=>$reasons];
    }
}
