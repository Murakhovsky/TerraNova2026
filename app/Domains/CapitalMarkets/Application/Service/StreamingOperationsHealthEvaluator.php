<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;

/**
 * Deterministic alert projection from a tenant-filtered, read-only operations
 * snapshot. This is a signal surface, NOT durable incident management.
 */
final readonly class StreamingOperationsHealthEvaluator
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array{health:string,alerts:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function evaluate(array $snapshot):array
    {
        $alerts=[];
        $tally=['CRITICAL'=>0,'WARNING'=>0];
        foreach(($snapshot['leases']??[]) as $lease){
            if(!is_array($lease)||!is_string($lease['lease_state']??null)
                ||!is_string($lease['source_id']??null)
                ||!is_string($lease['shard_key']??null)){
                throw new DomainException('Malformed streaming lease health snapshot.');
            }
            if($lease['lease_state']==='EXPIRED'){
                $alerts[]=[
                    'severity'=>'CRITICAL','code'=>'STREAM_LEASE_EXPIRED',
                    'source_id'=>$lease['source_id'],'shard_key'=>$lease['shard_key'],
                    'count'=>1,'operator_action'=>'CHECK_SWEEPER_OR_RESTART',
                ];
                $tally['CRITICAL']++;
            }elseif(!in_array($lease['lease_state'],['ACTIVE','RELEASED'],true)){
                throw new DomainException('Unrecognized streaming lease status.');
            }
        }
        foreach(($snapshot['quality_by_source']??[]) as $source=>$kinds){
            if(!is_string($source)||!is_array($kinds)){
                throw new DomainException('Malformed streaming quality health snapshot.');
            }
            foreach(['trading','reference'] as $kind){
                $row=$kinds[$kind]??[];
                if(!is_array($row))throw new DomainException('Unknown streaming market state category.');
                $bad=0;$degraded=0;
                foreach($row as $status=>$amount){
                    if(!in_array($status,['TRUSTED','DEGRADED','STALE','UNTRUSTED','UNAVAILABLE'],true)
                        ||!is_int($amount)||$amount<0){
                        throw new DomainException('Corrupt streaming trust status count.');
                    }
                    if(in_array($status,['STALE','UNTRUSTED','UNAVAILABLE'],true))$bad+=$amount;
                    if($status==='DEGRADED')$degraded+=$amount;
                }
                if($bad>0){
                    $alerts[]=[
                        'severity'=>'CRITICAL','code'=>'MARKET_STATE_NOT_TRUSTED',
                        'source_id'=>$source,'market_state_kind'=>$kind,'count'=>$bad,
                        'operator_action'=>'INVESTIGATE_DATA_QUALITY',
                    ];
                    $tally['CRITICAL']++;
                }
                if($degraded>0){
                    $alerts[]=[
                        'severity'=>'WARNING','code'=>'MARKET_STATE_DEGRADED',
                        'source_id'=>$source,'market_state_kind'=>$kind,'count'=>$degraded,
                        'operator_action'=>'REVIEW_QUALITY_POLICY',
                    ];
                    $tally['WARNING']++;
                }
            }
        }
        $hasEvidence=($snapshot['leases']??[])!==[]
            ||($snapshot['quality_by_source']??[])!==[];
        return [
            'health'=>$tally['CRITICAL']>0?'CRITICAL':
                ($tally['WARNING']>0?'WARNING':($hasEvidence?'OK':'NO_EVIDENCE')),
            'alerts'=>$alerts,'counts'=>$tally,
        ];
    }
}
