<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;
use DomainException;
use DateTimeImmutable;
use DateTimeZone;
final class SalesOrderJourneyEvidence
{
    public static function normalize(array $e,string $placed):array
    {
        if(($e['model']??null)!=='shopify-last-visit-utm-v1'||count($e)!==5)throw new DomainException('Invalid journey model.');
        foreach(['utm_source','utm_medium'] as $k)if(!is_string($e[$k]??null)||!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$e[$k]))throw new DomainException('Invalid journey tag.');
        if(!is_string($e['utm_campaign']??null)||!preg_match('/^(?:mkt|mkpl)-[a-f0-9]{24}$/D',$e['utm_campaign']))throw new DomainException('Unknown journey tag.');
        $at=$e['visit_at']??null;
        foreach([$at,$placed] as $s){
            if(!is_string($s)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$s))throw new DomainException('Invalid journey time.');
            $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$s,new DateTimeZone('UTC'));
            if(!$d||$d->format('Y-m-d\TH:i:s.u\Z')!==$s)throw new DomainException('Invalid journey date.');
        }
        if($at>$placed)throw new DomainException('Visit must precede order.');
        return ['model'=>$e['model'],'visit_at'=>$at,'utm_source'=>$e['utm_source'],'utm_medium'=>$e['utm_medium'],'utm_campaign'=>$e['utm_campaign']];
    }
}
