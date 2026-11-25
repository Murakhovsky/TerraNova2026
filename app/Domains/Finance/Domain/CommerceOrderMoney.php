<?php
declare(strict_types=1);
namespace Domains\Finance\Domain;
use DomainException;

final class CommerceOrderMoney
{
    public static function amount(mixed $v,bool $zero=false):int
    {
        if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$v))throw new DomainException('Exact currency decimal required.');
        $n=(int)str_replace('.','',$v);
        if(!$zero&&$n===0)throw new DomainException('Positive amount required.');
        return $n;
    }
    public static function id(mixed $v):string
    {
        if(!is_string($v)||!preg_match('/^[a-f0-9]{32}$/D',$v))throw new DomainException('Invalid financial identity.');
        return $v;
    }
    public static function ref(mixed $v,int $max=191):string
    {
        if(!is_string($v)||strlen($v)>$max||!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]*$#D',$v))throw new DomainException('Invalid opaque reference.');
        return $v;
    }
    public static function currency(mixed $v):string
    {
        if(!in_array($v,['EUR','USD','UAH'],true))throw new DomainException('Unsupported currency.');
        return $v;
    }
    public static function kind(mixed $v):string
    {
        if(!in_array($v,['capture','refund','chargeback','chargeback_reversal'],true))throw new DomainException('Unsupported payment operation.');
        return $v;
    }
    public static function apply(array $b,string $kind,int $n,int $available):array
    {
        if($n<1)throw new DomainException('Positive amount required.');
        $c=(int)$b['captured_minor'];$r=(int)$b['refunded_minor'];$d=(int)$b['chargeback_minor'];
        if($kind==='capture')$c+=$n;
        elseif($n>$available)throw new DomainException('Operation exceeds confirmed parent balance.');
        elseif($kind==='refund')$r+=$n;
        elseif($kind==='chargeback')$d+=$n;
        elseif($kind==='chargeback_reversal')$d-=$n;
        else throw new DomainException('Unsupported operation.');
        if($d<0||$r+$d>$c||$c>99999999999999)throw new DomainException('Invalid collection balance.');
        return ['captured_minor'=>$c,'refunded_minor'=>$r,'chargeback_minor'=>$d];
    }
    private static function decimal(int $n):string{return intdiv($n,100).'.'.str_pad((string)($n%100),2,'0',STR_PAD_LEFT);}
    public static function projection(array $b):array
    {
        $net=(int)$b['captured_minor']-(int)$b['refunded_minor']-(int)$b['chargeback_minor'];
        return ['schema_version'=>'1.0.0','organization_id'=>$b['organization_id'],'order_id'=>$b['order_id'],
            'currency'=>$b['currency'],'evidence_reference'=>$b['evidence_reference'],'revision'=>(int)$b['revision'],
            'captured_amount'=>self::decimal((int)$b['captured_minor']),'refunded_amount'=>self::decimal((int)$b['refunded_minor']),
            'chargeback_amount'=>self::decimal((int)$b['chargeback_minor']),'net_collected_amount'=>self::decimal($net),
            'outstanding_amount'=>self::decimal(max(0,(int)$b['order_total_minor']-$net))];
    }
}
