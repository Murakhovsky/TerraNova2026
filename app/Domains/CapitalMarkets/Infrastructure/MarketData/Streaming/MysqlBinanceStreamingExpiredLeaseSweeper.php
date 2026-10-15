<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Independent, explicitly scoped dead-worker recovery for the single BBO
 * Binance pilot. No per-channel source-invalidation safe mode exists yet.
 *
 * Compare-and-swap the *observed* expired owner/token under a row lock.
 * Never steal a lease that has been renewed, replaced or released.
 */
final readonly class MysqlBinanceStreamingExpiredLeaseSweeper
{
    private const SHARD='binance-spot-single-bbo';

    public function __construct(
        private PDO $pdo,
        private StreamingTrustInvalidatorInterface $invalidator,
    ){}

    /** @return array{status:string,invalidated:int,skipped:int} */
    public function sweep(string $organizationId,string $sourceId):array
    {
        if($organizationId===''||$sourceId===''||strlen($organizationId)>190||strlen($sourceId)>190){
            throw new DomainException('Invalid streaming sweep organization/source.');
        }
        $snapshot=$this->pdo->prepare(
            'SELECT owner_id,fencing_token FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id<>\'\' AND expires_at<=UTC_TIMESTAMP(6) LIMIT 1'
        );
        $args=['org'=>$organizationId,'source'=>$sourceId,'shard'=>self::SHARD];
        $snapshot->execute($args);
        $observed=$snapshot->fetch(PDO::FETCH_ASSOC);
        if(!is_array($observed))return ['status'=>'NO_EXPIRED_LEASE','invalidated'=>0,'skipped'=>0];

        $workerId='binance-stream-sweeper-'.bin2hex(random_bytes(10));
        $fenceToken=null;
        $this->pdo->beginTransaction();
        try{
            // Same ordering as REST publication and lease acquisition:
            // source sentinel first, stream shard second.
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$organizationId,$sourceId);
            $lock=$this->pdo->prepare(
                'SELECT owner_id,fencing_token FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id<>\'\' AND expires_at<=UTC_TIMESTAMP(6) FOR UPDATE'
            );
            $lock->execute($args);
            $actual=$lock->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actual)
                ||$actual['owner_id']!==$observed['owner_id']
                ||(string)$actual['fencing_token']!==(string)$observed['fencing_token']){
                $this->pdo->commit();
                return ['status'=>'OWNER_CHANGED','invalidated'=>0,'skipped'=>1];
            }
            $old=(int)$actual['fencing_token'];
            if($old<1||$old===PHP_INT_MAX)throw new DomainException('Invalid sweep fencing state.');
            $fenceToken=$old+1;
            $replace=$this->pdo->prepare(
                'UPDATE tn_capital_market_stream_worker_leases
                 SET owner_id=:worker,fencing_token=:token,
                     expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND),
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:observed_owner AND fencing_token=:observed_token
                   AND expires_at<=UTC_TIMESTAMP(6)'
            );
            $replace->execute([
                ...$args,'worker'=>$workerId,'token'=>$fenceToken,
                'observed_owner'=>$observed['owner_id'],
                'observed_token'=>$observed['fencing_token'],
            ]);
            if($replace->rowCount()!==1)throw new RuntimeException('Expired stream lease takeover failed.');
            $this->pdo->commit();
        }catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }
        // Only the current sweep token may invalidate; a newly-started
        // worker always invalidates before its first quote is published.
        try{
            $count=$this->invalidator->invalidate(
                $organizationId,$sourceId,self::SHARD,$workerId,$fenceToken,'LEASE_EXPIRED'
            );
        }catch(Throwable $error){
            // Keep the expiring sweeper lease owned on failure so this can be
            // retried on a later sweep. Do NOT silently forget unsafe state.
            throw $error;
        }
        $release=$this->pdo->prepare(
            'UPDATE tn_capital_market_stream_worker_leases
             SET owner_id=\'\',expires_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
               AND owner_id=:worker AND fencing_token=:token'
        );
        $release->execute([...$args,'worker'=>$workerId,'token'=>$fenceToken]);
        if($release->rowCount()!==1)throw new RuntimeException('Streaming sweeper lost ownership during release.');
        return ['status'=>'EXPIRED_INVALIDATED','invalidated'=>$count,'skipped'=>0];
    }
}
