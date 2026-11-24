<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Initial P0: one AdSet, bounded UTC dates, selected two-decimal currencies. */
final class MarketingPaidFundingPolicy
{
    public static function date(string $date):DateTimeImmutable
    {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Exact UTC calendar date required.');
        return $parsed;
    }
    /** @return array{days:int,daily_minor:int,lifetime_minor:int} */
    public static function budget(string $decimal,string $currency,string $start,string $end):array
    {
        if(!in_array($currency,['USD','EUR','UAH','PLN'],true)
            ||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\\.([0-9]{1,2}))?$/D',$decimal,$m)){
            throw new DomainException('M3 P0 supports explicit two-decimal currencies and bounded decimal budgets.');
        }
        $a=self::date($start);$b=self::date($end);
        if($b<$a||$a->diff($b)->days>30)throw new DomainException('Funding window must contain 1–31 UTC days.');
        $days=(int)$a->diff($b)->days+1;
        $minor=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
        if($minor<1||$minor>intdiv(1_000_000_000_000,$days))throw new DomainException('Funding amount outside Finance bounds.');
        return ['days'=>$days,'daily_minor'=>$minor,'lifetime_minor'=>$minor*$days];
    }

    /** Observed native reservation is never a spend lock. Exact operation and amount only. */
    public static function matches(array $plan,array $observation,string $envelopeId,string $reservationId,string $today):bool
    {
        $e=$observation['envelope']??[];$r=$observation['reservation']??[];
        return ($e['id']??null)===$envelopeId&&($r['id']??null)===$reservationId
            &&($e['state']??null)==='ACTIVE'&&($r['state']??null)==='RESERVED'
            &&($e['source_domain']??null)==='marketing'&&($r['source_domain']??null)==='marketing'
            &&($e['source_ref']??null)===$plan['id']&&($r['envelope_id']??null)===$envelopeId
            &&($r['source_operation_key']??null)===$plan['finance_operation_key']
            &&($e['currency']??null)===$plan['currency']&&($r['amount_minor']??null)===$plan['lifetime_minor']
            &&is_int($e['reserved_minor']??null)&&$e['reserved_minor']>=$plan['lifetime_minor']
            &&is_int($e['available_minor']??null)&&$e['available_minor']>=0
            &&is_int($e['revision']??null)&&$e['revision']>0
            &&is_string($e['period_start']??null)&&$e['period_start']<=$today&&$e['period_start']<=$plan['period_start']
            &&is_string($e['period_end']??null)&&$e['period_end']>=$plan['period_end']
            &&$today<=$plan['period_end']
            &&is_string($r['action_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$r['action_id'])===1;
    }
}
