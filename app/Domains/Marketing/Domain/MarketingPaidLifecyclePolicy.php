<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/** P0 staged, single AdSet + single Ad. Remote activation still requires separate approval and Finance claim. */
final class MarketingPaidLifecyclePolicy
{
    /** @return list<string> */
    public static function prerequisites(string $operation):array
    {
        return match($operation){
            'CREATE_CAMPAIGN'=>[],
            'CREATE_ADSET'=>['CREATE_CAMPAIGN'],
            'CREATE_AD'=>['CREATE_CAMPAIGN','CREATE_ADSET'],
            'ACTIVATE_AD'=>['CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD'],
            'ACTIVATE_ADSET'=>['ACTIVATE_AD'],
            'ACTIVATE_CAMPAIGN'=>['ACTIVATE_AD','ACTIVATE_ADSET'],
            'PAUSE_CAMPAIGN'=>['CREATE_CAMPAIGN'],
            default=>throw new DomainException('Unknown paid operation.')
        };
    }

    public static function assertPausedHierarchy(array $plan,string $account,string $campaignId,string $adsetId,string $adId,
        string $creativeId,array $campaign,array $adset,array $ad,bool $childrenActive=false):void
    {
        foreach([[$campaign,$campaignId,'PAUSED'],[$adset,$adsetId,$childrenActive?'ACTIVE':'PAUSED'],[$ad,$adId,$childrenActive?'ACTIVE':'PAUSED']] as [$row,$id,$status]){
            if(($row['id']??null)!==$id||($row['account_id']??null)!==substr($account,4)||($row['configured_status']??null)!==$status)
                throw new DomainException('Hierarchy is not verified paused in exact account.');
        }
        $utc=new \DateTimeZone('UTC');
        $start=MarketingPaidFundingPolicy::date($plan['period_start'])->getTimestamp();
        $end=MarketingPaidFundingPolicy::date($plan['period_end'])->modify('+1 day')->getTimestamp();
        try{$remoteStart=(new \DateTimeImmutable((string)($adset['start_time']??''),$utc))->getTimestamp();
            $remoteEnd=(new \DateTimeImmutable((string)($adset['end_time']??''),$utc))->getTimestamp();}
        catch(\Throwable){throw new DomainException('Meta delivery dates not verified.');}
        if(($campaign['objective']??null)!=='OUTCOME_TRAFFIC'||($campaign['special_ad_categories']??null)!==[]
            ||!in_array($campaign['daily_budget']??null,[null,'0',0],true)||!in_array($campaign['lifetime_budget']??null,[null,'0',0],true)
            ||($adset['campaign_id']??null)!==$campaignId||($ad['adset_id']??null)!==$adsetId
            ||($ad['creative']['id']??null)!==$creativeId||!in_array($adset['daily_budget']??null,[null,'0',0],true)
            ||(string)($adset['lifetime_budget']??'')!==(string)$plan['lifetime_minor']
            ||!is_string($adset['start_time']??null)||!is_string($adset['end_time']??null)
            ||$remoteStart!==$start||$remoteEnd!==$end){
            throw new DomainException('Provider cap, dates, creative or hierarchy differs from approved plan.');
        }
    }

    /** Exact imported source evidence compared with a known native binding; not final settlement. */
    public static function spendComparison(array $plan,string $campaignId,array $snapshot):array
    {
        if(($snapshot['ad_account_ref']??null)!==$plan['account_id']||($snapshot['external_id']??null)!==$campaignId
            ||($snapshot['grain']??null)!=='campaign'||($snapshot['currency']??null)!==$plan['currency']
            ||($snapshot['timezone']??null)!=='UTC'||$plan['account_timezone']!=='UTC'
            ||($snapshot['window_start']??null)!==$plan['period_start']||($snapshot['window_end']??null)!==$plan['period_end']){
            throw new DomainException('Exact UTC campaign reporting scope required; no inferred timezone conversion.');
        }
        $raw=$snapshot['reported_spend_decimal']??null;
        if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\\.([0-9]{1,6}))?$/D',$raw,$m))
            throw new DomainException('Unusable source spend decimal.');
        $fraction=str_pad($m[2]??'',6,'0');$micro=(int)$m[1]*1000000+(int)$fraction;
        $cap=$plan['lifetime_minor']*10000;
        return ['status'=>$micro>$cap?'SOURCE_EXCEEDS_PLANNED_CAP':'SOURCE_WITHIN_PLANNED_CAP',
            'source_snapshot_ref'=>$snapshot['source_snapshot_ref'],'source_revision'=>$snapshot['source_revision'],
            'reported_spend_decimal'=>$raw,'planned_cap_minor'=>$plan['lifetime_minor'],'currency'=>$plan['currency'],
            'finance_settled'=>false,'reservation_release_authorized'=>false,'expense_confirmed'=>false];
    }
}
