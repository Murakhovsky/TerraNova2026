<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;

/** Conservative comparative traffic-cost hypothesis. No budget authority. */
final class MarketingBudgetOptimizationPolicy
{
    public static function preview(array $a,array $b):array
    {
        foreach(['account_id','account_revision','currency','window_start','window_end','attribution_hash'] as $k)
            if(($a[$k]??null)===null||$a[$k]!==($b[$k]??null))throw new DomainException('Budget comparison requires identical native reporting scopes.');
        if($a['plan_id']===$b['plan_id']||$a['campaign_id']===$b['campaign_id']||$a['provider_campaign_id']===$b['provider_campaign_id'])
            throw new DomainException('Budget comparison requires independent campaigns.');
        $rows=[$a,$b];
        usort($rows,static fn(array $x,array $y):int=>strcmp($x['plan_id'],$y['plan_id']));
        $items=[];$reliable=true;
        foreach($rows as $r){
            if(!is_int($r['daily_minor']??null)||$r['daily_minor']<1||$r['daily_minor']>1_000_000_000_000
                ||!is_int($r['lifetime_minor']??null)||$r['lifetime_minor']<1||$r['lifetime_minor']>1_000_000_000_000)
                throw new DomainException('Budget amount outside Finance planning bounds.');
            $spend=MarketingMediaAnalyticsPolicy::micro($r['reported_spend_decimal']);
            $clicks=$r['clicks'];$imp=$r['impressions'];
            $cpc=is_int($clicks)&&$clicks>0?$spend/$clicks/1000000:null;
            if(!is_int($imp)||$imp<1000||!is_int($clicks)||$clicks<20||$spend<=0||$spend>$r['lifetime_minor']*10000)
                $reliable=false;
            $items[]=['plan_id'=>$r['plan_id'],'campaign_id'=>$r['campaign_id'],'plan_hash'=>$r['plan_hash'],
                'snapshot_ref'=>$r['snapshot_ref'],'source_revision'=>$r['source_revision'],'source_hash'=>$r['source_hash'],
                'observed_impressions'=>$imp,'observed_clicks'=>$clicks,'reported_spend_decimal'=>$r['reported_spend_decimal'],
                'observed_cpc'=>$cpc===null?null:round($cpc,6),'current_daily_minor'=>$r['daily_minor'],
                'candidate_daily_minor'=>$r['daily_minor']];
        }
        $status=$reliable?'NO_MATERIAL_DIFFERENCE':'INSUFFICIENT_OR_OVERCAP_EVIDENCE';$delta=0;
        if($reliable){
            $c0=MarketingMediaAnalyticsPolicy::micro($rows[0]['reported_spend_decimal'])/$rows[0]['clicks'];
            $c1=MarketingMediaAnalyticsPolicy::micro($rows[1]['reported_spend_decimal'])/$rows[1]['clicks'];
            $donor=$c0>$c1?0:1;$recipient=1-$donor;
            if(max($c0,$c1)>=1.25*min($c0,$c1)){
                $delta=min(intdiv($items[$donor]['current_daily_minor'],10),intdiv($items[$recipient]['current_daily_minor'],10));
                if($delta>0&&$items[$recipient]['current_daily_minor']+$delta<=1_000_000_000_000){
                    $items[$donor]['candidate_daily_minor']-=$delta;$items[$recipient]['candidate_daily_minor']+=$delta;
                    $status='REVIEW_BOUNDED_REALLOCATION';
                }else{$delta=0;$status='NO_SAFE_MINOR_UNIT_SHIFT';}
            }
        }
        $total=array_sum(array_column($items,'current_daily_minor'));
        if($total!==array_sum(array_column($items,'candidate_daily_minor')))throw new DomainException('Budget conservation failed.');
        return ['model'=>'traffic-budget-preview-v1','status'=>$status,'currency'=>$a['currency'],'window_start'=>$a['window_start'],
            'window_end'=>$a['window_end'],'account_id'=>$a['account_id'],'total_daily_minor'=>$total,'shift_minor'=>$delta,'items'=>$items,
            'statistical_significance_confirmed'=>false,'causal_attribution'=>false,'revenue_optimized'=>false,
            'finance_reservation_confirmed'=>false,'paid_activation_approved'=>false,'provider_mutation'=>false,'financial_mutation'=>false,
            'approval_required_for_execution'=>true];
    }
}
