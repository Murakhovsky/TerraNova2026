<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Policy;

use DomainException;
use Domains\Sales\Model\SalesCurrency;

/** Bounded deterministic AND predicates over native Deal fields. No expressions or arbitrary SQL. */
final class PipelineTransitionConditions
{
    public static function validate(array $conditions):void
    {
        if (!array_is_list($conditions)||count($conditions)>12) throw new DomainException('Invalid transition condition list.');
        foreach($conditions as $c){
            if (!is_array($c)||!is_string($c['field']??null)||!is_string($c['operator']??null)
                || array_diff(array_keys($c),['field','operator','value'])!==[]) throw new DomainException('Invalid transition predicate.');
            $f=$c['field'];$op=$c['operator'];$value=$c['value']??null;
            $valid=match($f){
                'deal_value'=>in_array($op,['eq','gte','lte'],true)&&self::decimal($value),
                'currency'=>$op==='eq'&&is_string($value)&&SalesCurrency::accepts($value),
                'assigned_user_id'=>$op==='present'&&!array_key_exists('value',$c)
                    ||$op==='eq'&&is_int($value)&&$value>0,
                'status'=>$op==='eq'&&in_array($value,['active','paused'],true),
                default=>false,
            };
            if (!$valid) throw new DomainException('Unsupported transition predicate.');
        }
    }

    public static function satisfied(array $conditions,array $deal):bool
    {
        try{self::validate($conditions);}catch(DomainException){return false;}
        foreach($conditions as $c){
            $actual=$deal[$c['field']]??null;$expected=$c['value']??null;
            if($c['field']==='deal_value'){
                if(!self::decimal($actual))return false;
                // Exact comparison of bounded fixed precision decimals.
                $comparison=strcmp(str_pad(str_replace('.','',$actual),14,'0',STR_PAD_LEFT),str_pad(str_replace('.','',$expected),14,'0',STR_PAD_LEFT));
                $ok=match($c['operator']){'eq'=>$comparison===0,'gte'=>$comparison>=0,'lte'=>$comparison<=0};
            }elseif($c['field']==='assigned_user_id'){
                $ok=$c['operator']==='present'?(int)$actual>0:(int)$actual===$expected;
            }else{$ok=$actual===$expected;}
            if(!$ok)return false;
        }
        return true;
    }

    private static function decimal(mixed $value):bool
    {
        return is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$value)===1;
    }
}
