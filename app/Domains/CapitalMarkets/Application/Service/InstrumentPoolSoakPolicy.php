<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;

/**
 * Evaluates persisted, per-symbol LIVE evidence. Synthetic fixtures exercise this
 * policy in CI but can NEVER count as production evidence.
 */
final readonly class InstrumentPoolSoakPolicy
{
    /**
     * @param list<array<string,mixed>> $rows Aggregated real canonical MySQL events
     * @return array<string,mixed>
     */
    public function evaluate(array $rows,int $minimumBbo=250,int $minimumL2=50):array
    {
        if($minimumBbo<1||$minimumBbo>1000||$minimumL2<1||$minimumL2>500||count($rows)>5000){
            throw new DomainException('Invalid production soak capacity.');
        }
        $ok=['BBO'=>0,'L2'=>0];
        $failures=[];
        $seen=[];
        foreach($rows as $row){
            if(!is_array($row)||!in_array($row['kind']??null,['BBO','L2'],true)
                ||!is_string($row['source_id']??null)||($row['source_id']??'')===''
                ||!is_string($row['instrument_id']??null)||($row['instrument_id']??'')===''){
                throw new DomainException('Malformed production per-symbol evidence.');
            }
            $kind=$row['kind'];
            $identity=$kind.'|'.$row['source_id'].'|'.$row['instrument_id'];
            if(isset($seen[$identity]))throw new DomainException('Duplicated production symbol evidence.');
            $seen[$identity]=true;
            $count=$row['samples']??null;
            $span=$row['span_seconds']??null;
            $age=$row['last_age_seconds']??null;
            $bad=$row['bad_samples']??null;
            foreach([$count,$span,$age,$bad] as $number){
                if(!is_int($number)||$number<0)throw new DomainException('Untrusted production sample counters.');
            }
            $reasons=[];
            if($row['data_mode']!=='LIVE')$reasons[]='NOT_LIVE';
            if(($row['source_admitted']??false)!==true)$reasons[]='SOURCE_NOT_ATTESTED';
            if(($row['live_worker']??false)!==true)$reasons[]='WORKER_NOT_LEASED';
            if($count<60||$span<82800)$reasons[]='SOAK_NOT_23H_COVERED';
            if($age>120)$reasons[]='MARKET_STALE';
            if($bad>0)$reasons[]='QUALITY_OR_SEQUENCE_GAP';
            if($reasons===[]){
                ++$ok[$kind];
            }else{
                $failures[]=['kind'=>$kind,'source_id'=>$row['source_id'],
                    'instrument_id'=>$row['instrument_id'],'reasons'=>$reasons];
            }
        }
        $gates=[
            'BBO_250'=>$ok['BBO'] >= $minimumBbo,
            'L2_50'=>$ok['L2'] >= $minimumL2,
            'NO_UNTRUSTED_SYMBOLS'=>$failures===[],
        ];
        return [
            'accepted'=>!in_array(false,$gates,true),
            'gates'=>$gates,'verified_counts'=>$ok,
            'required_counts'=>['BBO'=>$minimumBbo,'L2'=>$minimumL2],
            'evaluated_symbols'=>count($rows),
            'rejected_symbols'=>count($failures),
            'rejected_examples'=>array_slice($failures,0,20),
            'evidence_type'=>'LIVE_MYSQL_CANONICAL_AND_QUALITY',
            'no_fixture_substitution'=>true,
        ];
    }
}
