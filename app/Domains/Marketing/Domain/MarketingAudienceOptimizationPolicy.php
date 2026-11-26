<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;

/** Observational comparison of pinned country-set planning hypotheses, not an audience experiment. */
final class MarketingAudienceOptimizationPolicy
{
    public static function preview(array $a,array $b):array
    {
        // Reuse the terminal media comparison's exact scope, evidence thresholds and cap fences.
        $base=MarketingBudgetOptimizationPolicy::preview($a,$b);
        if($a['daily_minor']!==$b['daily_minor']||$a['lifetime_minor']!==$b['lifetime_minor']
            ||$a['creative_hash']!==$b['creative_hash']||$a['audience_id']===$b['audience_id'])
            throw new DomainException('Audience comparison requires equal planned budgets, identical creative content and independent audiences.');
        foreach([$a,$b] as $r){
            $countries=$r['planned_countries'];
            if(!is_array($countries)||!array_is_list($countries)||count($countries)<1||count($countries)>20
                ||count(array_unique($countries))!==count($countries))throw new DomainException('Invalid pinned country set.');
            foreach($countries as $code)if(!is_string($code)||!preg_match('/^[A-Z]{2}$/D',$code))
                throw new DomainException('Invalid pinned market country.');
        }
        if(array_intersect($a['planned_countries'],$b['planned_countries'])!==[])
            throw new DomainException('Overlapping planned country sets cannot be compared by this model.');
        $pins=[$a['plan_id']=>$a,$b['plan_id']=>$b];$items=[];
        foreach($base['items'] as $item){
            $p=$pins[$item['plan_id']];$countries=$p['planned_countries'];sort($countries,SORT_STRING);
            unset($item['candidate_daily_minor']);
            $items[]=[...$item,'audience_id'=>$p['audience_id'],'audience_revision'=>$p['audience_revision'],'planned_countries'=>$countries];
        }
        $status=match($base['status']){
            'INSUFFICIENT_OR_OVERCAP_EVIDENCE'=>'INSUFFICIENT_EVIDENCE',
            'NO_MATERIAL_DIFFERENCE'=>'NO_MATERIAL_DIFFERENCE',
            default=>'REVIEW_OBSERVED_DIFFERENCE',
        };
        $lower=null;
        if($status==='REVIEW_OBSERVED_DIFFERENCE'){
            $c0=MarketingMediaAnalyticsPolicy::micro($items[0]['reported_spend_decimal'])/$items[0]['observed_clicks'];
            $c1=MarketingMediaAnalyticsPolicy::micro($items[1]['reported_spend_decimal'])/$items[1]['observed_clicks'];
            $lower=$items[$c0<$c1?0:1]['plan_id'];
        }
        return ['model'=>'planned-country-set-comparison-v1','status'=>$status,'currency'=>$base['currency'],
            'window_start'=>$base['window_start'],'window_end'=>$base['window_end'],'account_id'=>$base['account_id'],
            'creative_hash'=>$a['creative_hash'],'lower_observed_cpc_plan_id'=>$lower,'items'=>$items,
            'actual_audience_delivery_confirmed'=>false,'randomized_experiment'=>false,'statistical_significance_confirmed'=>false,
            'causal_attribution'=>false,'targeting_change_recommended'=>false,'provider_mutation'=>false,'financial_mutation'=>false,
            'paid_activation_approved'=>false,'approval_required_for_execution'=>true];
    }
}
