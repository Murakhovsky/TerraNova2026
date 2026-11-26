<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
/** Fixed, review-only source observation thresholds, not optimization authority. */
final class MarketingOptimizationMonitorPolicy
{
    public static function analyze(array $current,array $baseline):array
    {
        if(($current['account_revision_current']??null)!==true||($baseline['account_revision_current']??null)!==true)
            throw new DomainException('Current account revision required.');
        MarketingMediaAnalyticsPolicy::comparison($current,$baseline);
        $old=[];foreach($baseline['rows'] as $r)$old[$r['external_id']]=$r;
        $items=[];$alerts=0;$unknown=0;$seen=[];
        foreach($current['rows'] as $r){
            $id=$r['external_id'];$seen[$id]=true;$b=$old[$id]??null;
            $flags=[];$state='COMPARABLE';
            if($b===null){$state='NO_BASELINE';$unknown++;}
            elseif(!is_int($r['impressions']??null)||!is_int($b['impressions']??null)||!is_int($r['clicks']??null)||!is_int($b['clicks']??null)
                ||$r['impressions']<1000||$b['impressions']<1000){$state='INSUFFICIENT_EVIDENCE';$unknown++;}
            else{
                $ctr=$r['clicks']/$r['impressions'];$bctr=$b['clicks']/$b['impressions'];
                $spend=MarketingMediaAnalyticsPolicy::micro($r['reported_spend_decimal']);$bspend=MarketingMediaAnalyticsPolicy::micro($b['reported_spend_decimal']);
                $ratio=static fn(float $a,float $b):?float=>$b>0?round($a/$b,4):null;
                $check=static function(string $code,?float $value,float $threshold,bool $below)use(&$flags):void{
                    if($value!==null&&($below?$value<=$threshold:$value>=$threshold))$flags[]=['code'=>$code,'ratio'=>$value];
                };
                $check('CTR_DECLINE',$ratio($ctr,$bctr),0.8,true);
                $check('CPM_INCREASE',$ratio($spend/$r['impressions'],$bspend/$b['impressions']),1.25,false);
                if($r['clicks']>=20&&$b['clicks']>=20)$check('CPC_INCREASE',$ratio($spend/$r['clicks'],$bspend/$b['clicks']),1.25,false);
                $sr=$ratio($spend,$bspend);$cr=$ratio($r['clicks'],$b['clicks']);
                if($sr!==null&&$sr>=1.25&&$cr!==null&&$cr<=0.8)$flags[]=['code'=>'SPEND_UP_CLICKS_DOWN','ratio'=>$sr];
                if($flags!==[]){$state='REVIEW_REQUIRED';$alerts++;}
            }
            $items[]=['external_id'=>(string)$id,'state'=>$state,'flags'=>$flags,
                'current_source_ref'=>$r['source_snapshot_ref'],'current_source_revision'=>$r['source_revision'],
                'baseline_source_ref'=>$b['source_snapshot_ref']??null,'baseline_source_revision'=>$b['source_revision']??null];
        }
        foreach($old as $id=>$r)if(!isset($seen[$id])){
            $unknown++;$items[]=['external_id'=>(string)$id,'state'=>'NO_CURRENT_OBSERVATION','flags'=>[],
                'current_source_ref'=>null,'current_source_revision'=>null,'baseline_source_ref'=>$r['source_snapshot_ref'],'baseline_source_revision'=>$r['source_revision']];
        }
        usort($items,static fn(array $a,array $b)=>strcmp($a['external_id'],$b['external_id']));
        return ['model'=>'monitor-v1','account_id'=>$current['account_id'],'grain'=>$current['grain'],'currency'=>$current['currency'],
            'current_run_id'=>$current['id'],'baseline_run_id'=>$baseline['id'],'alert_count'=>$alerts,'insufficient_count'=>$unknown,'items'=>$items,
            'thresholds'=>['minimum_impressions'=>1000,'minimum_clicks_for_cpc'=>20,'ctr_decline_ratio'=>0.8,'cost_increase_ratio'=>1.25],
            'provider_mutation'=>false,'financial_mutation'=>false,'causal_attribution'=>false,'statistical_significance_confirmed'=>false];
    }
}
