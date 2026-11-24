<?php
declare(strict_types=1);
namespace Domains\Finance\Domain;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Meta-reported Campaign spend remains unverified evidence, never an Expense.
 * Raw provider decimals must stay canonical decimal strings, never float.
 */
final class FinanceSpendObservationPolicy
{
    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function validate(array $raw):array
    {
        $required=['ad_account_ref','source_snapshot_ref','grain','external_id','window_start','window_end',
            'timezone','currency','attribution_hash','source_hash','source_revision','source_status','reported_spend_decimal'];
        foreach(array_keys($raw) as $key){
            if(!in_array($key,$required,true))throw new DomainException('Unknown or privileged Finance spend evidence field.');
        }
        foreach($required as $field){
            if(!array_key_exists($field,$raw))throw new DomainException('Finance spend evidence missing required source metadata.');
        }
        foreach($required as $field){
            if($field!=='source_revision' && !is_string($raw[$field])){
                throw new DomainException('Finance spend evidence must use typed decimal strings.');
            }
        }
        if(!preg_match('/^mads-[a-f0-9]{24}$/D',$raw['ad_account_ref'])
            ||!preg_match('/^mis-[a-f0-9]{24}$/D',$raw['source_snapshot_ref'])
            ||$raw['grain']!=='campaign'
            ||!preg_match('/^[0-9]{1,100}$/D',$raw['external_id'])
            ||!preg_match('/^[A-Z]{3}$/D',$raw['currency'])
            ||!preg_match('/^[a-f0-9]{64}$/D',$raw['attribution_hash'])
            ||!preg_match('/^[a-f0-9]{64}$/D',$raw['source_hash'])
            ||!in_array($raw['source_status'],['PROVISIONAL','REVISED','FINALIZED_BY_SOURCE'],true)){
            throw new DomainException('Finance cannot accept unscoped or malformed Meta observation evidence.');
        }
        if(!is_int($raw['source_revision'])||$raw['source_revision']<1||$raw['source_revision']>2000000000){
            throw new DomainException('Invalid provider snapshot source revision.');
        }
        if(!preg_match('/^(0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D',$raw['reported_spend_decimal'])){
            throw new DomainException('Provider spend requires a nonnegative decimal string with at most six fractional places.');
        }
        // Strict calendar period; do not compare timezone-converted dates or mix reporting periods.
        $from=DateTimeImmutable::createFromFormat('!Y-m-d',$raw['window_start']);
        $to=DateTimeImmutable::createFromFormat('!Y-m-d',$raw['window_end']);
        if(!$from||!$to||$from->format('Y-m-d')!==$raw['window_start']
            ||$to->format('Y-m-d')!==$raw['window_end']
            ||$from>$to||$from->diff($to)->days>31){
            throw new DomainException('Finance spend observation must cover a valid bounded reporting window.');
        }
        try{$zone=new DateTimeZone($raw['timezone']);}
        catch(\Throwable){throw new DomainException('Invalid provider timezone.');}
        if($zone->getName()!==$raw['timezone']||strlen($raw['timezone'])>80){
            throw new DomainException('Finance evidence timezone must be explicit.');
        }
        return [
            'provider'=>'meta','source_domain'=>'marketing',
            'ad_account_ref'=>$raw['ad_account_ref'],
            'source_snapshot_ref'=>$raw['source_snapshot_ref'],
            'grain'=>'campaign','external_id'=>$raw['external_id'],
            'window_start'=>$raw['window_start'],'window_end'=>$raw['window_end'],
            'timezone'=>$raw['timezone'],'currency'=>$raw['currency'],
            'attribution_hash'=>$raw['attribution_hash'],'source_hash'=>$raw['source_hash'],
            'source_revision'=>$raw['source_revision'],'source_status'=>$raw['source_status'],
            'reported_spend_decimal'=>$raw['reported_spend_decimal'],
            'evidence_verification'=>'UNVERIFIED_SOURCE'
        ];
    }

    /** @return array<string,string> */
    public function scope(string $account,string $from,string $to,string $currency,string $tz,string $attribution):array
    {
        // Reuse the exact same source validation for both write and read scopes.
        $checked=$this->validate([
            'ad_account_ref'=>$account,'source_snapshot_ref'=>'mis-'.str_repeat('a',24),
            'grain'=>'campaign','external_id'=>'1','window_start'=>$from,'window_end'=>$to,
            'currency'=>$currency,'timezone'=>$tz,'attribution_hash'=>$attribution,
            'source_hash'=>str_repeat('0',64),'source_revision'=>1,
            'source_status'=>'PROVISIONAL','reported_spend_decimal'=>'0'
        ]);
        return array_intersect_key($checked,array_flip(['ad_account_ref','window_start','window_end','timezone','currency','attribution_hash']));
    }
}

