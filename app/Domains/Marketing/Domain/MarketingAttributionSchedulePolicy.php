<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
use DateTimeImmutable;
final class MarketingAttributionSchedulePolicy
{
    public static function parse(string $json):array
    {
        if(strlen($json)>16384)throw new DomainException('Schedule configuration too large.');
        $rows=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>20)throw new DomainException('Attribution schedule limited to 20 profiles.');
        $seen=[];$out=[];
        foreach($rows as $r){
            if(!is_array($r))throw new DomainException('Invalid attribution schedule profile.');
            $keys=array_keys($r);sort($keys,SORT_STRING);
            if($keys!==['actor_id','campaign_id','lookback_days','max_pages','organization_id','plan_id','profile_id','source_kind'])
                throw new DomainException('Unexpected attribution profile fields.');
            foreach(['profile_id'=>'/^[a-z0-9][a-z0-9_.-]{0,63}$/D','organization_id'=>'/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,39}$/D',
                'actor_id'=>'/^[1-9][0-9]{0,17}$/D','campaign_id'=>'/^mkt-[a-f0-9]{24}$/D'] as $key=>$pattern)
                if(!is_string($r[$key])||!preg_match($pattern,$r[$key]))throw new DomainException('Invalid attribution profile identity.');
            if($r['plan_id']!==null&&(!is_string($r['plan_id'])||!preg_match('/^mkpl-[a-f0-9]{24}$/D',$r['plan_id'])))
                throw new DomainException('Invalid scheduled paid plan.');
            if(!in_array($r['source_kind'],['lead','order'],true)||!is_int($r['lookback_days'])||$r['lookback_days']<1||$r['lookback_days']>30
                ||!is_int($r['max_pages'])||$r['max_pages']<1||$r['max_pages']>5)throw new DomainException('Invalid attribution profile bounds.');
            $k=$r['organization_id'].':'.$r['profile_id'];
            if(isset($seen[$k]))throw new DomainException('Duplicate attribution schedule profile.');
            $seen[$k]=true;ksort($r,SORT_STRING);$out[]=$r;
        }
        return $out;
    }
    public static function window(DateTimeImmutable $today,int $lookback,?array $plan,string $kind):?array
    {
        if($lookback<1||$lookback>30||!in_array($kind,['lead','order'],true))throw new DomainException('Invalid scheduled window.');
        $start=$today->modify('-'.$lookback.' days')->format('Y-m-d');$end=$today->modify('-1 day')->format('Y-m-d');
        if($plan!==null){
            $start=max($start,$plan['period_start']);
            $last=MarketingAttributionPolicy::date($plan['period_end']);
            if($kind==='order')$last=$last->modify('+30 days');
            $end=min($end,$last->format('Y-m-d'));
        }
        return $start>$end?null:[$start,$end];
    }
}
