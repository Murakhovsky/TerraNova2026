<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use PDO;
use RuntimeException;

/**
 * Tenant-filtered, read-only operator projection; it never acquires or
 * revokes leases and never returns worker IDs, credentials or raw quotes.
 */
final readonly class MysqlStreamingOperationsSnapshot
{
    public function __construct(private PDO $pdo){}

    /** @return array<string,mixed> */
    public function inspect(string $organizationId,?string $sourceId=null):array
    {
        $this->identity($organizationId);
        if($sourceId!==null)$this->identity($sourceId);
        if($this->pdo->inTransaction()){
            throw new DomainException('Streaming snapshot requires isolated read.');
        }
        $where='organization_id=:org'.($sourceId===null?'':' AND source_id=:source');
        $args=['org'=>$organizationId];
        if($sourceId!==null)$args['source']=$sourceId;

        $stmt=$this->pdo->prepare(
            "SELECT source_id,shard_key,owner_id,
             CASE WHEN owner_id='' THEN 'RELEASED'
                  WHEN expires_at<=UTC_TIMESTAMP(6) THEN 'EXPIRED'
                  ELSE 'ACTIVE' END AS lease_state,
             TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),expires_at) AS remaining_seconds,
             updated_at FROM tn_capital_market_stream_worker_leases
             WHERE ".$where." ORDER BY source_id,shard_key LIMIT 501"
        );
        $stmt->execute($args);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>500)throw new RuntimeException('Streaming operations scope exceeds 500 leases.');
        $leases=[];
        $counts=['ACTIVE'=>0,'EXPIRED'=>0,'RELEASED'=>0];
        foreach($rows as $row){
            $state=(string)$row['lease_state'];
            if(!array_key_exists($state,$counts))throw new RuntimeException('Unknown lease state.');
            $counts[$state]++;
            $leases[]=[
                'source_id'=>(string)$row['source_id'],
                'shard_key'=>(string)$row['shard_key'],
                'lease_state'=>$state,
                'remaining_seconds'=>$state==='ACTIVE'?max(0,(int)$row['remaining_seconds']):0,
                'updated_at'=>(string)$row['updated_at'],
            ];
        }

        $quality=[];
        foreach(['trading'=>'tn_capital_market_states','reference'=>'tn_capital_market_reference_states'] as $kind=>$table){
            $q=$this->pdo->prepare(
                'SELECT source_id,trust_status,COUNT(*) AS count_rows FROM '.$table.
                ' WHERE '.$where.' GROUP BY source_id,trust_status ORDER BY source_id,trust_status LIMIT 501'
            );
            $q->execute($args);
            $grouped=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($grouped)>500)throw new RuntimeException('Streaming quality scope exceeds 500 groups.');
            foreach($grouped as $row){
                $key=(string)$row['source_id'];
                $trust=(string)$row['trust_status'];
                if(!in_array($trust,['TRUSTED','DEGRADED','STALE','UNTRUSTED','UNAVAILABLE'],true)){
                    throw new RuntimeException('Unexpected Market State trust status.');
                }
                if(!isset($quality[$key]))$quality[$key]=[
                    'trading'=>['TRUSTED'=>0,'DEGRADED'=>0,'STALE'=>0,'UNTRUSTED'=>0,'UNAVAILABLE'=>0],
                    'reference'=>['TRUSTED'=>0,'DEGRADED'=>0,'STALE'=>0,'UNTRUSTED'=>0,'UNAVAILABLE'=>0],
                ];
                $quality[$key][$kind][$trust]=(int)$row['count_rows'];
            }
        }
        ksort($quality,SORT_STRING);

        $wanted=$this->pdo->prepare(
            "SELECT sub.source_id,COUNT(*) AS requested
             FROM tn_capital_market_subscriptions sub
             JOIN tn_capital_market_data_sources src
               ON src.organization_id=sub.organization_id AND src.source_id=sub.source_id
             WHERE sub.organization_id=:org".($sourceId===null?'':' AND sub.source_id=:source').
             " AND sub.status='ACTIVE' AND sub.data_type='BBO'
               AND src.enabled=1
               AND src.adapter_type IN ('binance.spot.rest','binance.usdm.perpetual.bbo.v1',
                    'bybit.spot.v5','bybit.linear-perpetual.v5',
                    'kraken.spot.rest','massive.stocks.v2')
             GROUP BY sub.source_id LIMIT 501");
        $wanted->execute($args);
        $desiredBbo=[];
        foreach($wanted->fetchAll(PDO::FETCH_ASSOC) as $sub){
            $desiredBbo[$sub['source_id']]=(int)$sub['requested'];
        }
        $acks=$this->pdo->prepare(
            "SELECT p.source_id,COUNT(*) AS receipts,
                SUM(CASE WHEN p.status='LIVE_QUOTE'
                   AND p.quote_count>0 AND p.last_quality_score>=80
                   AND p.last_live_quote_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND)
                   AND l.owner_id<>'' AND l.fencing_token=p.fencing_token
                   AND l.expires_at>UTC_TIMESTAMP(6)
                    THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN p.status='REVOKED' THEN 1 ELSE 0 END) AS revoked
             FROM tn_capital_market_provider_stream_receipts p
             LEFT JOIN tn_capital_market_stream_worker_leases l
               ON l.organization_id=p.organization_id
              AND l.source_id=p.source_id AND l.shard_key=p.shard_key
             WHERE p.organization_id=:org".($sourceId===null?'':' AND p.source_id=:source')."
             GROUP BY p.source_id LIMIT 501");
        $acks->execute($args);
        $provider=[];
        foreach($acks->fetchAll(PDO::FETCH_ASSOC) as $receipt){
            $provider[$receipt['source_id']]=[
                'receipts'=>(int)$receipt['receipts'],
                'confirmed_live'=>(int)$receipt['confirmed'],
                'revoked'=>(int)$receipt['revoked'],
            ];
        }
        foreach($desiredBbo as $source=>$count){
            $provider[$source]??=['receipts'=>0,'confirmed_live'=>0,'revoked'=>0];
        }
        foreach($provider as $source=>&$numbers){
            $numbers['requested_bbo']=$desiredBbo[$source]??0;
        }
        unset($numbers);
        ksort($provider,SORT_STRING);
        return [
            'organization_id'=>$organizationId,
            'source_filter'=>$sourceId,
            'lease_counts'=>$counts,
            'leases'=>$leases,
            'quality_by_source'=>$quality,
            'provider_by_source'=>$provider,
            'operating_mode'=>'READ_ONLY',
        ];
    }

    private function identity(string $identity):void
    {
        if($identity===''||trim($identity)!==$identity||strlen($identity)>190){
            throw new DomainException('Invalid streaming operations organization/source identity.');
        }
    }
}
