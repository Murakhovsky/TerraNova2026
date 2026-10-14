<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Database-backed lease with monotonic fencing generation.
 *
 * Worker must compare its generation before every durable publication and
 * must invalidate source trust when losing ownership. The lease is NOT a
 * substitute for per-instrument sequence integrity.
 */
final readonly class MysqlStreamingWorkerLease implements StreamingWorkerLeaseInterface
{
    public function __construct(private PDO $pdo){}

    /**
     * @return int|null A new fencing token or null if another worker owns
     * the unexpired lease.
     */
    public function acquire(
        string $organizationId,
        string $sourceId,
        string $shard,
        string $workerId,
        int $ttlSeconds=30,
    ):?int{
        $this->validate($organizationId,$sourceId,$shard,$workerId,$ttlSeconds);
        if($shard===MysqlMarketSourceWriterGate::SOURCE_GUARD_SHARD){
            throw new DomainException('Reserved source guard cannot be acquired as a worker shard.');
        }
        $this->pdo->beginTransaction();
        try{
            // Serialize lease acquisition with REST canonical writes. Lock
            // source before shard to preserve a single global lock order.
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$organizationId,$sourceId);
            // A source may have only one live WebSocket shard. Massive batch
            // and the legacy one-symbol worker must never publish concurrently.
            $active=$this->pdo->prepare(
                "SELECT 1 FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source
                   AND shard_key<>:current_shard AND shard_key<>:guard_shard
                   AND owner_id<>'' AND expires_at>UTC_TIMESTAMP(6)
                 LIMIT 1 FOR UPDATE"
            );
            $active->execute([
                'org'=>$organizationId,'source'=>$sourceId,
                'current_shard'=>$shard,'guard_shard'=>MysqlMarketSourceWriterGate::SOURCE_GUARD_SHARD,
            ]);
            if($active->fetchColumn()!==false){
                $this->pdo->commit();
                return null;
            }
            // The unique key ensures one row, even for simultaneous first
            // acquisition attempts. Both read and mutation share a row lock.
            $insert=$this->pdo->prepare(
                'INSERT IGNORE INTO tn_capital_market_stream_worker_leases
                (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
                VALUES (:org,:source,:shard,\'\',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))'
            );
            $insert->execute(['org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard]);

            $select=$this->pdo->prepare(
                'SELECT owner_id,fencing_token,expires_at > UTC_TIMESTAMP(6) AS still_valid
                 FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard FOR UPDATE'
            );
            $select->execute(['org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new RuntimeException('Streaming worker lease row missing.');
            if((int)$row['still_valid']===1 && $row['owner_id']!==$workerId){
                $this->pdo->commit();
                return null;
            }
            // Even the same owner gets a new token on re-acquire: old process
            // generations cannot publish after a restart.
            $token=(int)$row['fencing_token']+1;
            $update=$this->pdo->prepare(
                'UPDATE tn_capital_market_stream_worker_leases
                 SET owner_id=:worker,fencing_token=:token,
                     expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL :ttl SECOND),
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard'
            );
            $update->execute([
                'worker'=>$workerId,'token'=>$token,'ttl'=>$ttlSeconds,
                'org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
            ]);
            $this->pdo->commit();
            return $token;
        }catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }
    }

    public function renew(
        string $organizationId,string $sourceId,string $shard,
        string $workerId,int $fencingToken,int $ttlSeconds=30,
    ):bool{
        $this->validate($organizationId,$sourceId,$shard,$workerId,$ttlSeconds);
        if($fencingToken<1)throw new DomainException('Invalid fencing token.');
        $sql='UPDATE tn_capital_market_stream_worker_leases
              SET expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL :ttl SECOND),
                  updated_at=UTC_TIMESTAMP(6)
              WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                AND owner_id=:worker AND fencing_token=:token
                AND expires_at > UTC_TIMESTAMP(6)';
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute([
            'ttl'=>$ttlSeconds,'org'=>$organizationId,'source'=>$sourceId,
            'shard'=>$shard,'worker'=>$workerId,'token'=>$fencingToken,
        ]);
        return $stmt->rowCount()===1;
    }

    public function owns(
        string $organizationId,string $sourceId,string $shard,
        string $workerId,int $fencingToken,
    ):bool{
        if($fencingToken<1)return false;
        $stmt=$this->pdo->prepare(
            'SELECT 1 FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id=:worker AND fencing_token=:token
               AND expires_at > UTC_TIMESTAMP(6)'
        );
        $stmt->execute([
            'org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
            'worker'=>$workerId,'token'=>$fencingToken,
        ]);
        return $stmt->fetchColumn()!==false;
    }

    public function release(
        string $organizationId,string $sourceId,string $shard,
        string $workerId,int $fencingToken,
    ):void{
        if($fencingToken<1)throw new DomainException('Invalid fencing token.');
        $stmt=$this->pdo->prepare(
            'UPDATE tn_capital_market_stream_worker_leases
             SET expires_at=UTC_TIMESTAMP(6),owner_id=\'\',updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id=:worker AND fencing_token=:token'
        );
        $stmt->execute([
            'org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
            'worker'=>$workerId,'token'=>$fencingToken,
        ]);
    }

    private function validate(
        string $organizationId,string $sourceId,string $shard,string $workerId,int $ttlSeconds,
    ):void{
        foreach([$organizationId,$sourceId,$shard,$workerId] as $id){
            if($id===''||trim($id)!==$id||strlen($id)>190){
                throw new DomainException('Invalid streaming worker lease identity.');
            }
        }
        if($ttlSeconds<2||$ttlSeconds>3600){
            throw new DomainException('Worker lease TTL must be 2..3600 seconds.');
        }
    }
}
