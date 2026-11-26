<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class MarketingOptimizationSchedulePolicy
{
    public static function parse(string $json):array
    {
        if(strlen($json)>16384)throw new DomainException('Optimization schedule configuration too large.');
        $rows=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>20)throw new DomainException('Optimization schedule limited to 20 profiles.');
        $seen=[];$scope=[];$actors=[];$out=[];
        foreach($rows as $r){
            if(!is_array($r))throw new DomainException('Invalid optimization profile.');
            $keys=array_keys($r);sort($keys,SORT_STRING);
            if($keys!==['account_id','actor_id','external_id','lookback_days','organization_id','profile_id','rule','threshold_percent'])
                throw new DomainException('Unexpected optimization schedule fields.');
            foreach(['profile_id'=>'/^[a-z0-9][a-z0-9_.-]{0,63}$/D','organization_id'=>'/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,39}$/D',
                'actor_id'=>'/^[1-9][0-9]{0,17}$/D','account_id'=>'/^mads-[a-f0-9]{24}$/D','external_id'=>'/^[0-9]{1,30}$/D'] as $k=>$pattern)
                if(!is_string($r[$k])||!preg_match($pattern,$r[$k]))throw new DomainException('Invalid optimization schedule identity.');
            $min=match($r['rule']){'CTR_DECLINE'=>20,'CPC_INCREASE','CPM_INCREASE'=>25,default=>throw new DomainException('Unsupported scheduled rule.')};
            if(!is_int($r['lookback_days'])||$r['lookback_days']<1||$r['lookback_days']>30||!is_int($r['threshold_percent'])
                ||$r['threshold_percent']<$min||$r['threshold_percent']>($r['rule']==='CTR_DECLINE'?90:200))throw new DomainException('Invalid scheduled rule bounds.');
            $k=$r['organization_id'].':'.$r['profile_id'];$a=$r['organization_id'].':'.$r['account_id'];
            $target=$a.':'.$r['external_id'].':'.$r['rule'];
            if(isset($seen[$k])||isset($scope[$target])||(isset($actors[$a])&&$actors[$a]!==$r['actor_id']))
                throw new DomainException('Duplicate rule target or conflicting schedule actor.');
            $seen[$k]=true;$scope[$target]=true;$actors[$a]=$r['actor_id'];
            ksort($r,SORT_STRING);$out[]=$r;
        }
        return $out;
    }
    public static function windows(DateTimeImmutable $clock,int $days):array
    {
        if($days<1||$days>30)throw new DomainException('Invalid monitoring period.');
        $today=$clock->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
        return ['cycle_date'=>$today->format('Y-m-d'),'window_start'=>$today->modify('-'.$days.' days')->format('Y-m-d'),
            'window_end'=>$today->modify('-1 day')->format('Y-m-d'),'baseline_start'=>$today->modify('-'.(2*$days).' days')->format('Y-m-d'),
            'baseline_end'=>$today->modify('-'.($days+1).' days')->format('Y-m-d')];
    }
}
