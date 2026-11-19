<?php
declare(strict_types=1);

namespace Domains\Finance\Domain;

use DateTimeImmutable;
use DomainException;

/**
 * Money is ALWAYS positive integer minor units (no floats or locale parsing).
 * No creation of provider expenditure or accounting Expense from this policy.
 */
final class BudgetControlPolicy
{
    public static function id(string $id,string $kind='finb'):string
    {
        if(!preg_match('/^'.preg_quote($kind,'/').'[a-f0-9]{24}$/D',$id))throw new DomainException('Invalid Finance reference.');
        return $id;
    }

    public static function key(string $key):string
    {
        if(!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key))throw new DomainException('Stable bounded Finance idempotency key required.');
        return $key;
    }

    public static function amount(mixed $amount):int
    {
        if(!is_int($amount)||$amount<1||$amount>1_000_000_000_000)throw new DomainException('Finance minor units must be bounded positive integers.');
        return $amount;
    }

    public static function revision(mixed $revision):int
    {
        if(!is_int($revision)||$revision<1||$revision>2_000_000_000)throw new DomainException('Invalid optimistic budget revision.');
        return $revision;
    }

    public static function operationKey(string $key):string
    {
        if(!preg_match('/^[a-zA-Z0-9_.:-]{8,191}$/D',$key))throw new DomainException('Provider operation key required.');
        return $key;
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    public function draft(array $raw):array
    {
        foreach(array_keys($raw) as $k){
            if(!in_array($k,['name','source_domain','source_ref','currency','period_start','period_end','limit_minor'],true)){
                throw new DomainException('Unknown Finance budget draft field.');
            }
        }
        foreach(['name','source_domain','source_ref','currency','period_start','period_end'] as $k){
            if(!is_string($raw[$k]??null))throw new DomainException('Finance budget text fields must be strings.');
        }
        $name=trim($raw['name']);$sourceDomain=trim($raw['source_domain']);$sourceRef=trim($raw['source_ref']);
        if(mb_strlen($name)<3||mb_strlen($name)>190)throw new DomainException('Budget name must be 3-190 characters.');
        if($sourceDomain!=='marketing'||!preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/D',$sourceRef)){
            throw new DomainException('F1 accepts only explicitly identified Marketing plans.');
        }
        $currency=$raw['currency'];
        if(!preg_match('/^[A-Z]{3}$/D',$currency))throw new DomainException('Explicit ISO-style currency required.');
        $start=$this->date($raw['period_start']);$end=$this->date($raw['period_end']);
        if($start>$end||$start->diff($end)->days>366)throw new DomainException('Budget period must be ordered and at most 367 days.');
        return [
            'name'=>$name,'source_domain'=>$sourceDomain,'source_ref'=>$sourceRef,
            'currency'=>$currency,'period_start'=>$start->format('Y-m-d'),
            'period_end'=>$end->format('Y-m-d'),
            'limit_minor'=>self::amount($raw['limit_minor']??null),
        ];
    }

    /** @return array{source_domain:string,source_operation_key:string,amount_minor:int,reason:string,expected_revision:int} */
    public function reserveInput(mixed $amount,mixed $revision,mixed $sourceDomain,mixed $operationKey,mixed $reason):array
    {
        if($sourceDomain!=='marketing'||!is_string($operationKey)||!is_string($reason)
            ||trim($reason)===''||mb_strlen(trim($reason))>250)throw new DomainException('Invalid governed budget reservation context.');
        return [
            'source_domain'=>'marketing','source_operation_key'=>self::operationKey($operationKey),
            'amount_minor'=>self::amount($amount),'reason'=>trim($reason),
            'expected_revision'=>self::revision($revision),
        ];
    }

    public function reason(mixed $reason):string
    {
        if(!is_string($reason)||trim($reason)===''||mb_strlen(trim($reason))>250){
            throw new DomainException('A bounded Finance operation reason is required.');
        }
        return trim($reason);
    }

    public function date(string $value):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException('Invalid Finance ISO date.');
        return $date;
    }
}

