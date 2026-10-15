<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DomainException;
/** Explicit operator-approved discovery targets; never discovers tenants implicitly. */
final readonly class InstrumentPoolScheduleTargets
{
    /** @return list<array{organization_id:string,provider:string,venue_id:string}> */
    public static function parse(string $json):array
    {
        if(strlen($json)>16384)throw new DomainException('Instrument Pool scheduler config too large.');
        try{$targets=json_decode($json,true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new DomainException('Invalid Instrument Pool schedule JSON.');}
        if(!is_array($targets)||!array_is_list($targets)||count($targets)>25){
            throw new DomainException('Instrument Pool schedule is limited to 25 targets.');
        }
        $seen=[];$out=[];
        foreach($targets as $target){
            if(!is_array($target)||array_is_list($target)){
                throw new DomainException('Invalid Instrument Pool schedule entry.');
            }
            $keys=array_keys($target);sort($keys,SORT_STRING);
            if($keys!==['organization_id','provider','venue_id']){
                throw new DomainException('Invalid Instrument Pool schedule schema.');
            }
            $org=$target['organization_id'];$provider=$target['provider'];$venue=$target['venue_id'];
            if(!is_string($org)||!is_string($venue)||!is_string($provider)
                ||$org===''||$venue===''||trim($org)!==$org||trim($venue)!==$venue
                ||strlen($org)>190||strlen($venue)>190
                ||!in_array($provider,['BINANCE','BYBIT','KRAKEN'],true)){
                throw new DomainException('Invalid Instrument Pool schedule scope.');
            }
            $key=implode('|',[$org,$provider,$venue]);
            if(isset($seen[$key]))throw new DomainException('Duplicate Instrument Pool schedule target.');
            $seen[$key]=true;
            $out[]=['organization_id'=>$org,'provider'=>$provider,'venue_id'=>$venue];
        }
        usort($out,static fn(array $a,array $b):int=>strcmp(
            implode('|',$a),implode('|',$b)
        ));
        return $out;
    }
}
