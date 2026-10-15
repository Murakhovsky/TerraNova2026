<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRestWriterGateInterface;
use Domains\CapitalMarkets\Application\Exception\MarketSourceStreamingActiveException;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use PDO;

/**
 * Serializes REST publication against worker lease admission per tenant/source.
 *
 * Both modes acquire the same sentinel row lock first. Source lock is held
 * throughout the entire REST health + canonical publication transaction.
 * Worker acquisition locks it before becoming an active stream owner.
 *
 * All affected repositories/transaction managers MUST share the same MySQL
 * PDO instance. Do not lock the row while performing outbound HTTP requests.
 */
final readonly class MysqlMarketSourceWriterGate implements MarketSourceRestWriterGateInterface
{
    public const SOURCE_GUARD_SHARD='__source_writer_gate__';

    public function __construct(
        private PDO $pdo,
        private TransactionManagerInterface $transactions,
    ){}

    public static function lockSource(PDO $pdo,string $organizationId,string $sourceId):void
    {
        foreach([$organizationId,$sourceId] as $id){
            if($id===''||trim($id)!==$id||strlen($id)>190){
                throw new DomainException('Invalid market source writer identity.');
            }
        }
        if(!$pdo->inTransaction()){
            throw new DomainException('Source writer lock requires a database transaction.');
        }
        $params=['org'=>$organizationId,'source'=>$sourceId,'shard'=>self::SOURCE_GUARD_SHARD];
        $insert=$pdo->prepare(
            "INSERT IGNORE INTO tn_capital_market_stream_worker_leases
             (organization_id,source_id,shard_key,owner_id,fencing_token,expires_at,updated_at)
             VALUES (:org,:source,:shard,'',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))"
        );
        $insert->execute($params);
        $lock=$pdo->prepare(
            'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
             WHERE organization_id=:org AND source_id=:source AND shard_key=:shard FOR UPDATE'
        );
        $lock->execute($params);
        if($lock->fetchColumn()===false){
            throw new DomainException('Source-scoped writer guard unavailable.');
        }
    }

    public function executeRest(string $organizationId,string $sourceId,callable $write):mixed
    {
        // A caller-owned transaction might have read stale data before this
        // lock. Restrict the gate to its own transaction boundary.
        if($this->pdo->inTransaction()){
            throw new DomainException('REST writer admission requires an outer transaction.');
        }
        return $this->transactions->transactional(function() use(
            $organizationId,$sourceId,$write
        ):mixed{
            self::lockSource($this->pdo,$organizationId,$sourceId);
            $active=$this->pdo->prepare(
                "SELECT 1 FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source
                   AND shard_key<>:guard_shard AND owner_id<>''
                   AND expires_at>UTC_TIMESTAMP(6)
                 LIMIT 1 FOR UPDATE"
            );
            $active->execute([
                'org'=>$organizationId,
                'source'=>$sourceId,
                'guard_shard'=>self::SOURCE_GUARD_SHARD,
            ]);
            if($active->fetchColumn()!==false){
                throw new MarketSourceStreamingActiveException(
                    'REST market data skipped: streaming source owns the writer lease.'
                );
            }
            return $write();
        });
    }
}
