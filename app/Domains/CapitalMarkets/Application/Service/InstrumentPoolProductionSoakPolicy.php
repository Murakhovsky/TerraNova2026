<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;

/**
 * Final release gate. A source with merely configured subscriptions is NOT live.
 * The input must originate from persisted LIVE canonical + quality rows, NOT fixtures.
 */
final readonly class InstrumentPoolProductionSoakPolicy
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function evaluate(array $rows, bool $windowElapsed, int $minBbo=250, int $minL2=50):array
    {
        if($minBbo<1||$minBbo>1000||$minL2<1||$minL2>500||count($rows)>3000){
            throw new DomainException('Invalid production soak bounds.');
        }
        $good=['BBO'=>0,'L2'=>0];$fail=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row)||!in_array($row['kind']??null,['BBO','L2'],true)
                ||!is_string($row['source_id']??null)||($row['source_id']??'')===''
                ||!is_string($row['instrument_id']??null)||($row['instrument_id']??'')===''){
                throw new DomainException('Invalid per-instrument production evidence.');
            }
            $kind=$row['kind'];
            $key=$kind.'|'.$row['source_id'].'|'.$row['instrument_id'];
            if(isset($seen[$key]))throw new DomainException('Duplicate production instrument evidence.');
            $seen[$key]=true;
            foreach(['samples','hour_buckets','five_minute_buckets','bad_samples','age_seconds'] as $field){
                if(!is_int($row[$field]??null)||$row[$field]<0){
                    throw new DomainException('Incomplete or invalid production event counters.');
                }
            }
            $reasons=[];
            if(($row['mode']??null)!=='LIVE')$reasons[]='NOT_LIVE';
            if(($row['approved_entitlement']??false)!==true)$reasons[]='ENTITLEMENT_UNVERIFIED';
            if(($row['issuer_verified']??false)!==true)$reasons[]='ISSUER_UNVERIFIED';
            if(($row['source_enabled']??false)!==true)$reasons[]='SOURCE_DISABLED';
            if(($row['worker_active']??false)!==true)$reasons[]='NO_ACTIVE_FENCED_WORKER';
            if(($row['samples']??0)<276||($row['hour_buckets']??0)<24
                ||($row['five_minute_buckets']??0)<276)$reasons[]='24H_CONTINUITY_NOT_PROVEN';
            if(($row['bad_samples']??0)>0)$reasons[]='UNTRUSTED_OR_MISSING_QUALITY';
            if(($row['age_seconds']??999999)>300)$reasons[]='STALE_LAST_EVENT';
            if($reasons===[])++$good[$kind];
            else $fail[]=['kind'=>$kind,'source_id'=>$row['source_id'],
                'instrument_id'=>$row['instrument_id'],'reasons'=>$reasons];
        }
        $gates=[
            '24H_ELAPSED'=>$windowElapsed,
            'BBO_250'=>$good['BBO'] >= $minBbo,
            'L2_50'=>$good['L2'] >= $minL2,
            'ALL_OBSERVED_SYMBOLS_TRUSTED'=>$fail===[],
        ];
        return [
            'accepted'=>!in_array(false,$gates,true),
            'gates'=>$gates,'verified'=>$good,
            'required'=>['BBO'=>$minBbo,'L2'=>$minL2],
            'observed'=>count($rows),'rejected'=>count($fail),
            'rejected_examples'=>array_slice($fail,0,25),
            'evidence_class'=>'LIVE_CANONICAL_MYSQL_QUALITY_24H',
        ];
    }
}
