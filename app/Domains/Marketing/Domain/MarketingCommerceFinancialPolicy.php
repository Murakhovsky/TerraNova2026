<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;
use DomainException;
final class MarketingCommerceFinancialPolicy
{
    public static function verify(array $f,string $org,string $order,string $currency):array
    {
        if(($f['schema_version']??null)!=='1.0.0'||($f['organization_id']??null)!==$org||($f['order_id']??null)!==$order
            ||($f['currency']??null)!==$currency||!is_int($f['revision']??null)||$f['revision']<1
            ||!is_string($f['evidence_reference']??null)||$f['evidence_reference']===''||strlen($f['evidence_reference'])>191)
            throw new DomainException('Finance evidence identity or revision differs.');
        $map=['captured_amount'=>'captured_minor','refunded_amount'=>'refunded_minor','chargeback_amount'=>'chargeback_minor','net_collected_amount'=>'net_collected_minor'];
        $out=[];
        foreach($map as $src=>$key)$out[$key]=self::minor($f[$src]??null);
        self::minor($f['outstanding_amount']??null);
        if($out['captured_minor']!==$out['refunded_minor']+$out['chargeback_minor']+$out['net_collected_minor'])
            throw new DomainException('Finance collection evidence does not reconcile.');
        return $out;
    }
    private static function minor(mixed $v):int
    {
        if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$v))throw new DomainException('Unusable Finance decimal.');
        return (int)str_replace('.','',$v);
    }
}
