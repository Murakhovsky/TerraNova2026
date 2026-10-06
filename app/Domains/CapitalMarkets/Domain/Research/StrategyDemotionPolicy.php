<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

final class StrategyDemotionPolicy
{
    public function evaluate(array $actual,array $policy):array
    {
        $reasons=[];
        foreach($policy as $metric=>$rule){
            $value=$actual[$metric]??null;
            if(!is_array($rule)||!is_numeric($value))continue;
            if(isset($rule['max'])&&(float)$value>(float)$rule['max'])$reasons[]=$metric.'_ABOVE_MAX';
            if(isset($rule['min'])&&(float)$value<(float)$rule['min'])$reasons[]=$metric.'_BELOW_MIN';
        }
        if($reasons===[])return ['action'=>'NONE','reasons'=>[]];

        $action=(string)($policy['action']??'RESEARCH_REQUIRED');
        if(!in_array($action,['REDUCE','RETURN_TO_PAPER','SUSPEND','REJECT','RESEARCH_REQUIRED'],true)){
            $action='RESEARCH_REQUIRED';
        }
        return ['action'=>$action,'reasons'=>$reasons];
    }
}
