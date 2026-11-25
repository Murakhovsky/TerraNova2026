<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
final class MarketingAttributionPolicy
{
    public const VERSION='utm-exact-v1';
    public static function window(string $from,string $to):void
    {
        $a=self::date($from);$b=self::date($to);
        if($a>$b||$a->diff($b)->days>90||$to>gmdate('Y-m-d'))throw new DomainException('Analytics requires a bounded historical 1-91 day window.');
    }
    public static function date(string $d):DateTimeImmutable
    {
        $t=DateTimeImmutable::createFromFormat('!Y-m-d',$d,new DateTimeZone('UTC'));
        if(!$t||$t->format('Y-m-d')!==$d)throw new DomainException('Invalid analytics calendar date.');
        return $t;
    }
    public static function evidence(array $lead,string $campaign,?string $plan):array
    {
        $tag=$plan??$campaign;
        if(!preg_match('/^mkt-[a-f0-9]{24}$/D',$campaign)||($plan!==null&&!preg_match('/^mkpl-[a-f0-9]{24}$/D',$plan))
            ||($lead['utm_campaign']??null)!==$tag||!is_string($lead['lead_id']??null)||!preg_match('/^[1-9][0-9]{0,17}$/D',$lead['lead_id'])
            ||!is_string($lead['created_at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D',$lead['created_at']))
            throw new DomainException('Exact native lead UTM campaign tag required.');
        $source=$lead['utm_source']??null;$medium=$lead['utm_medium']??null;
        foreach([$source,$medium] as $v)if(!is_string($v)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$v))throw new DomainException('Canonical UTM source and medium required.');
        if($plan!==null&&($source!=='meta'||$medium!=='paid_social'))throw new DomainException('Paid plan attribution requires meta / paid_social tag.');
        self::date(substr($lead['created_at'],0,10));
        $e=['lead_id'=>$lead['lead_id'],'plan_id'=>$plan,'created_at'=>$lead['created_at'],'utm_source'=>$source,'utm_medium'=>$medium,'utm_campaign'=>$tag];
        return [...$e,'evidence_hash'=>hash('sha256',json_encode($e,JSON_THROW_ON_ERROR)),'model'=>self::VERSION];
    }
    public static function count(mixed $v):?int
    {
        return (is_int($v)||is_string($v))&&preg_match('/^(0|[1-9][0-9]{0,11})$/D',(string)$v)?(int)$v:null;
    }
    /** Exact minor units, no inferred currency or budget-value fallback. */
    public static function minor(mixed $v):?int
    {
        if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2})0*)?$/D',$v,$m))return null;
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public static function ratio(?float $numerator,?float $denominator,float $scale=1):?float
    {
        return $numerator===null||$denominator===null||$denominator<=0?null:round($numerator/$denominator*$scale,4);
    }
    public static function media(array $row):array
    {
        $m=$row['metrics']??[];$i=self::count($m['impressions']??null);$click=self::count($m['clicks']??null);
        $raw=$row['spend_decimal']??null;
        if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$/D',$raw))throw new DomainException('Invalid media spend.');
        $spend=(float)$raw;
        return ['impressions'=>$i,'clicks'=>$click,'reported_spend_decimal'=>$raw,'ctr_percent'=>self::ratio($click,$i,100),
            'cpc'=>self::ratio($spend,$click),'cpm'=>self::ratio($spend,$i,1000),'currency'=>$row['currency'],
            'source_snapshot_ref'=>$row['id'],'source_revision'=>$row['revision'],'source_status'=>$row['source_status'],
            'fetched_at'=>$row['fetched_at']];
    }
}
