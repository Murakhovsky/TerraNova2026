<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** Versioned, bounded advisory rules over native observations. No arbitrary expression or execution. */
final class MarketingOptimizationRulePolicy
{
    public static function evaluate(array $current,array $baseline,string $externalId,string $rule,int $threshold):array
    {
        $minimum=match($rule){'CTR_DECLINE'=>20,'CPC_INCREASE','CPM_INCREASE'=>25,default=>throw new DomainException('Unknown optimization rule.')};
        $maximum=$rule==='CTR_DECLINE'?90:200;
        if($threshold<$minimum||$threshold>$maximum||!preg_match('/^[0-9]{1,30}$/D',$externalId))
            throw new DomainException('Rule threshold or source identity outside bounds.');
        if(($current['account_revision_current']??null)!==true||($baseline['account_revision_current']??null)!==true)
            throw new DomainException('Current native account revision required.');
        MarketingMediaAnalyticsPolicy::comparison($current,$baseline);
        $find=static function(array $batch)use($externalId):?array{
            foreach($batch['rows'] as $row)if($row['external_id']===$externalId)return $row;
            return null;
        };
        $a=$find($current);$b=$find($baseline);
        $state='INSUFFICIENT_EVIDENCE';$pct=null;$av=null;$bv=null;
        $metric=match($rule){'CTR_DECLINE'=>'CTR_PERCENT','CPC_INCREASE'=>'CPC','CPM_INCREASE'=>'CPM'};
        $valid=static fn(?array $row):bool=>$row!==null&&is_int($row['impressions']??null)&&$row['impressions']>=1000;
        $value=static function(array $row)use($metric):?float{
            return match($metric){
                'CTR_PERCENT'=>is_int($row['clicks']??null)?100.0*$row['clicks']/$row['impressions']:null,
                'CPC'=>is_int($row['clicks']??null)&&$row['clicks']>=20?MarketingMediaAnalyticsPolicy::micro($row['reported_spend_decimal'])/1000000/$row['clicks']:null,
                'CPM'=>MarketingMediaAnalyticsPolicy::micro($row['reported_spend_decimal'])/1000000/$row['impressions']*1000,
            };
        };
        if($valid($a)&&$valid($b)){
            $av=$value($a);$bv=$value($b);
            if($av!==null&&$bv!==null&&$bv>0){
                $delta=100*($av/$bv-1.0);
                $pct=round($delta,6);
                $state=($rule==='CTR_DECLINE'?$pct<=-$threshold:$pct>=$threshold)?'TRIGGERED_REVIEW_REQUIRED':'NOT_TRIGGERED';
            }
        }
        $pin=static fn(?array $row):?array=>$row===null?null:[
            'snapshot_ref'=>$row['source_snapshot_ref'],'revision'=>$row['source_revision'],'source_hash'=>$row['source_hash']];
        $result=['model'=>'optimization-rule-v1','rule'=>$rule,'threshold_percent'=>$threshold,'metric'=>$metric,
            'minimum_impressions'=>1000,'minimum_clicks_for_cpc'=>20,
            'status'=>$state,'account_id'=>$current['account_id'],'account_revision'=>$current['account_revision'],
            'currency'=>$current['currency'],'grain'=>$current['grain'],'external_id'=>$externalId,
            'current_run_id'=>$current['id'],'baseline_run_id'=>$baseline['id'],'window_start'=>$current['window_start'],
            'window_end'=>$current['window_end'],'baseline_start'=>$baseline['window_start'],'baseline_end'=>$baseline['window_end'],
            'attribution_hash'=>$current['attribution_hash'],'current_source'=>$pin($a),'baseline_source'=>$pin($b),
            'current_value'=>$av===null?null:round($av,6),'baseline_value'=>$bv===null?null:round($bv,6),'change_percent'=>$pct,
            'review_recommended'=>$state==='TRIGGERED_REVIEW_REQUIRED','action_created'=>false,'provider_mutation'=>false,
            'financial_mutation'=>false,'causal_attribution'=>false,'approval_required_for_execution'=>true];
        return [...$result,'evaluation_hash'=>MarketingExperimentPolicy::digest($result)];
    }
}
