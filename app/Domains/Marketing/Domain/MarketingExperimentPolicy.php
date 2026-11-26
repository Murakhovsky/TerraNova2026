<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** Pre-registered single-factor creative protocol. Campaign aggregate reports are never randomized evidence. */
final class MarketingExperimentPolicy
{
    public static function factor(string $value):string
    {
        if(!in_array($value,['HEADLINE','PRIMARY_TEXT'],true))throw new DomainException('Unsupported experimental creative factor.');
        return strtolower($value);
    }

    public static function digest(array $v):string
    {
        $normalize=static function(array $value)use(&$normalize):array{
            if(!array_is_list($value))ksort($value,SORT_STRING);
            foreach($value as &$item)if(is_array($item))$item=$normalize($item);unset($item);
            return $value;
        };
        return hash('sha256',json_encode($normalize($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    /** Identical planned controls, separate campaign/creative identities and one changed treatment. */
    public static function protocol(array $control,array $candidate,string $factor,string $utcToday):array
    {
        self::factor($factor);
        foreach(['account_id','account_revision','currency','account_timezone','period_start','period_end','daily_minor',
            'lifetime_minor','audience_id','audience_revision','campaign_scope_hash','creative_controls_hash'] as $key)
            if(($control[$key]??null)===null||$control[$key]!==($candidate[$key]??null))
                throw new DomainException('Experimental controls differ.');
        if($control['account_timezone']!=='UTC'||$control['period_start']<=$utcToday
            ||$control['plan_id']===$candidate['plan_id']||$control['campaign_id']===$candidate['campaign_id']
            ||$control['creative_id']===$candidate['creative_id']||$control['creative_hash']===$candidate['creative_hash']
            ||$control['treatment_hash']===$candidate['treatment_hash'])
            throw new DomainException('Future, independently pinned single-factor plans required.');
        return ['model'=>'creative-ab-protocol-v1','factor'=>$factor,'metric'=>'TRAFFIC_CPC',
            'target_allocation_percent'=>50,'minimum_impressions_per_arm'=>1000,'minimum_clicks_per_arm'=>20,
            'material_cpc_ratio_percent'=>125,'control'=>$control,'candidate'=>$candidate];
    }

    public static function observe(array $protocol,array $control,array $candidate):array
    {
        foreach([[$protocol['control'],$control],[$protocol['candidate'],$candidate]] as [$pin,$source]){
            foreach(['plan_id','plan_hash','campaign_id','account_id','account_revision','currency','daily_minor','lifetime_minor'] as $key)
                if(($source[$key]??null)!==$pin[$key])throw new DomainException('Experiment observation differs from registered arm.');
            if($source['window_start']!==$pin['period_start']||$source['window_end']!==$pin['period_end'])
                throw new DomainException('Experiment observation differs from registered window.');
        }
        $base=MarketingBudgetOptimizationPolicy::preview($control,$candidate);
        $status='INSUFFICIENT_EVIDENCE';$lower=null;
        if($base['status']!=='INSUFFICIENT_OR_OVERCAP_EVIDENCE'){
            $a=MarketingMediaAnalyticsPolicy::micro($control['reported_spend_decimal'])/$control['clicks'];
            $b=MarketingMediaAnalyticsPolicy::micro($candidate['reported_spend_decimal'])/$candidate['clicks'];
            $status='NO_MATERIAL_OBSERVED_DIFFERENCE';
            if(max($a,$b)>=1.25*min($a,$b)){
                $status='REVIEW_OBSERVED_DIFFERENCE';$lower=$a<$b?$control['plan_id']:$candidate['plan_id'];
            }
        }
        $items=[];
        foreach($base['items'] as $item){unset($item['candidate_daily_minor']);$items[$item['plan_id']]=$item;}
        return ['model'=>'creative-observation-v1','status'=>$status,'factor'=>$protocol['factor'],
            'metric'=>$protocol['metric'],'currency'=>$base['currency'],'window_start'=>$base['window_start'],'window_end'=>$base['window_end'],
            'control'=>$items[$control['plan_id']],'candidate'=>$items[$candidate['plan_id']],
            'lower_observed_cpc_plan_id'=>$lower,'randomization_verified'=>false,'statistical_significance_confirmed'=>false,
            'causal_attribution'=>false,'winning_creative_confirmed'=>false,'provider_mutation'=>false,'financial_mutation'=>false,
            'paid_activation_approved'=>false,'approval_required_for_execution'=>true];
    }
}
