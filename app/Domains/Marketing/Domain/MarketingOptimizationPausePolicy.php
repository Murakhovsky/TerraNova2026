<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
use DateTimeImmutable;
use DateTimeZone;

/** A triggered signal alone never authorizes provider mutation; this only establishes source eligibility. */
final class MarketingOptimizationPausePolicy
{
    public static function pin(array $pin):void
    {
        $keys=array_keys($pin);sort($keys,SORT_STRING);
        if($keys!==['review_evaluation_id','review_id','review_receipt_hash','review_run_id'])throw new DomainException('Exact optimization review pin required.');
        foreach(['review_evaluation_id','review_run_id'] as $field)
            if(!is_string($pin[$field])||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$pin[$field]))throw new DomainException('Invalid review reference.');
        if(!is_string($pin['review_id'])||!preg_match('/^mkor-[a-f0-9]{24}$/D',$pin['review_id'])
            ||!is_string($pin['review_receipt_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$pin['review_receipt_hash']))throw new DomainException('Invalid review receipt pin.');
    }
    public static function verify(array $plan,array $evaluation,array $rows,string $today):void
    {
        $cutoff=(new DateTimeImmutable($today,new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
        if($evaluation['status']!=='TRIGGERED_REVIEW_REQUIRED'||$evaluation['grain']!=='campaign'
            ||$evaluation['account_id']!==$plan['account_id']||$evaluation['account_revision']!==$plan['account_revision']
            ||$evaluation['currency']!==$plan['currency']||$plan['account_timezone']!=='UTC'
            ||$evaluation['baseline_start']<$plan['period_start']||$evaluation['window_end']>$plan['period_end']
            ||$evaluation['window_end']<$cutoff||$evaluation['window_end']>=$today||$today>$plan['period_end'])
            throw new DomainException('Review is stale or outside exact active paid-plan scope.');
        foreach(['DEPLOY_PAUSED','CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD','ACTIVATE','ACTIVATE_AD','ACTIVATE_ADSET','ACTIVATE_CAMPAIGN'] as $op)
            if(($rows[$op]['state']??null)!=='CONFIRMED'||!is_array($rows[$op]['receipt']??null))
                throw new DomainException('Confirmed native deployment and activation required.');
        if(($rows['PAUSE']??null)!==null||($rows['PAUSE_CAMPAIGN']??null)!==null)throw new DomainException('Pause already claimed.');
        $campaign=$evaluation['external_id'];
        foreach(['DEPLOY_PAUSED'=>'PAUSED','ACTIVATE'=>'ACTIVE_CONFIGURED'] as $op=>$state){
            $row=$rows[$op];$proof=$row['receipt'];
            if($row['provider_id']!==$campaign||($proof['provider_campaign_id']??null)!==$campaign
                ||($proof['plan_id']??null)!==$plan['id']||($proof['operation']??null)!==$op||($proof['state']??null)!==$state)
                throw new DomainException('Paid command receipt does not match reviewed campaign.');
        }
        if($rows['DEPLOY_PAUSED']['action_id']===$rows['ACTIVATE']['action_id'])throw new DomainException('Distinct approved commands required.');
        foreach(['CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD','ACTIVATE_CAMPAIGN','ACTIVATE_ADSET','ACTIVATE_AD'] as $op){
            $row=$rows[$op];$create=str_starts_with($op,'CREATE_');$parent=$rows[$create?'DEPLOY_PAUSED':'ACTIVATE'];
            if(($row['receipt']['provider_id']??null)!==$row['provider_id']||$row['action_id']!==$parent['action_id']
                ||($row['receipt']['configured_status']??null)!==($create?'PAUSED':'ACTIVE'))throw new DomainException('Paid step receipt mismatch.');
        }
        foreach(['CAMPAIGN','ADSET','AD'] as $kind)
            if($rows['CREATE_'.$kind]['provider_id']!==$rows['ACTIVATE_'.$kind]['provider_id'])throw new DomainException('Paid hierarchy changed.');
        if($rows['CREATE_CAMPAIGN']['provider_id']!==$campaign)throw new DomainException('Reviewed campaign is not native paid campaign.');
    }
}
