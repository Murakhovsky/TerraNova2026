<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;

final class MarketingMediaAnalyticsPolicy
{
    public static function scope(string $grain,string $from,string $to):void
    {
        MarketingAttributionPolicy::window($from,$to);
        if(!in_array($grain,['account','campaign','adset','ad'],true)
            ||MarketingAttributionPolicy::date($from)->diff(MarketingAttributionPolicy::date($to))->days>30)
            throw new DomainException('Media import requires a single grain and exact 1-31 day window.');
    }
    public static function sanitize(array $account,string $grain,string $from,string $to,array $rows):array
    {
        self::scope($grain,$from,$to);
        if(!array_is_list($rows)||count($rows)>1000||!preg_match('/^act_([0-9]{1,30})$/D',$account['ad_account_id']??'',$match))
            throw new DomainException('Incomplete or invalid media batch.');
        $field=['account'=>'account_id','campaign'=>'campaign_id','adset'=>'adset_id','ad'=>'ad_id'][$grain];
        $out=[];
        foreach($rows as $r){
            if(!is_array($r)||($r['date_start']??null)!==$from||($r['date_stop']??null)!==$to
                ||(string)($r['account_id']??'')!==$match[1]||!is_string($r[$field]??null)
                ||!preg_match('/^[0-9]{1,30}$/D',$r[$field])||isset($out[$r[$field]]))
                throw new DomainException('Media row identity/window mismatch or duplicate.');
            $spend=$r['spend']??null;
            if(!is_string($spend)||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$/D',$spend))
                throw new DomainException('Missing or invalid reported media spend.');
            $metrics=['impressions','clicks','reach'];
            $item=['account_id'=>$match[1],$field=>$r[$field],'date_start'=>$from,'date_stop'=>$to,'spend'=>$spend];
            foreach($metrics as $m){
                $v=MarketingAttributionPolicy::count($r[$m]??null);
                if(array_key_exists($m,$r)&&$v===null)throw new DomainException('Malformed reported metric.');
                if($v!==null)$item[$m]=(string)$v;
            }
            // Provider actions are observational categories, never deduplicated native conversions.
            foreach(['actions','action_values'] as $k){
                if(!array_key_exists($k,$r))continue;
                if(!is_array($r[$k])||!array_is_list($r[$k])||count($r[$k])>100)throw new DomainException('Malformed provider action breakdown.');
                $seen=[];$result=[];
                foreach($r[$k] as $action){
                    $type=$action['action_type']??null;$value=$action['value']??null;
                    if(!is_string($type)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$type)||isset($seen[$type])
                        ||(!is_string($value)&&!is_int($value))||!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D',(string)$value))
                        throw new DomainException('Unusable provider action metric.');
                    $seen[$type]=true;$result[]=['action_type'=>$type,'value'=>(string)$value];
                }
                usort($result,static fn(array $a,array $b)=>strcmp($a['action_type'],$b['action_type']));$item[$k]=$result;
            }
            $out[$r[$field]]=$item;
        }
        ksort($out,SORT_STRING);return array_values($out);
    }
    public static function micro(string $raw):int
    {
        if(!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,6}))?$/D',$raw,$m))throw new DomainException('Media decimal outside bounds.');
        return (int)$m[1]*1000000+(int)str_pad($m[2]??'',6,'0');
    }
    public static function decimal(int $micro):string{return intdiv($micro,1000000).'.'.str_pad((string)($micro%1000000),6,'0',STR_PAD_LEFT);}
    public static function summarize(array $rows):array
    {
        $spend=0;$imp=0;$clicks=0;$knownImp=true;$knownClicks=true;
        foreach($rows as $r){
            $amount=self::micro($r['reported_spend_decimal']);
            if($amount>PHP_INT_MAX-$spend)throw new DomainException('Media total exceeds exact integer bounds.');
            $spend+=$amount;
            if($r['impressions']===null)$knownImp=false;else $imp+=$r['impressions'];
            if($r['clicks']===null)$knownClicks=false;else $clicks+=$r['clicks'];
        }
        // Empty complete provider response is an observed empty result, not a claim of no delivery.
        $i=$rows!==[]&&$knownImp?$imp:null;$c=$rows!==[]&&$knownClicks?$clicks:null;
        return ['row_count'=>count($rows),'reported_spend_decimal'=>self::decimal($spend),'impressions'=>$i,'clicks'=>$c,
            'ctr_percent'=>MarketingAttributionPolicy::ratio($c,$i,100),'cpc'=>MarketingAttributionPolicy::ratio($spend/1000000,$c),
            'cpm'=>MarketingAttributionPolicy::ratio($spend/1000000,$i,1000),'reach'=>null,'provider_conversion_total'=>null];
    }
    public static function comparison(array $current,array $baseline):array
    {
        foreach(['account_id','account_revision','grain','currency','timezone','attribution_hash'] as $k)
            if($current[$k]!==$baseline[$k])throw new DomainException('Media comparisons require same account revision and attribution scope.');
        $days=static fn(array $r)=>MarketingAttributionPolicy::date($r['window_start'])->diff(MarketingAttributionPolicy::date($r['window_end']))->days;
        if($baseline['window_end']>=$current['window_start']||$days($baseline)!==$days($current))throw new DomainException('Comparison windows must be equal-length and non-overlapping.');
        $a=self::summarize($current['rows']);$b=self::summarize($baseline['rows']);$out=[];
        foreach(['impressions','clicks','ctr_percent','cpc','cpm'] as $k){
            $out[$k]=$a[$k]===null||$b[$k]===null?null:round($a[$k]-$b[$k],4);
        }
        $out['reported_spend_delta_decimal']=self::signedDecimal(self::microTotal($a['reported_spend_decimal'])-self::microTotal($b['reported_spend_decimal']));
        return ['baseline_run_id'=>$baseline['id'],'baseline_window_start'=>$baseline['window_start'],'baseline_window_end'=>$baseline['window_end'],'deltas'=>$out,
            'provider_default_attribution_not_causal'=>true];
    }
    private static function microTotal(string $s):int
    {
        [$whole,$fraction]=explode('.',$s);
        return (int)$whole*1000000+(int)$fraction;
    }
    private static function signedDecimal(int $v):string{return ($v<0?'-':'').self::decimal(abs($v));}
}
