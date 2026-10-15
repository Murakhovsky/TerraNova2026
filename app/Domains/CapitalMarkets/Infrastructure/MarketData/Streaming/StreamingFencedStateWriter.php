<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use PDO;
use Throwable;

/**
 * Streaming-only, fenced publication of canonical states.
 *
 * Both MarketStateRepository and the lease MUST use the same PDO connection.
 * Locking the lease until transaction commit serializes acquisition/release
 * against the write, so a superseded owner cannot publish after takeover.
 * This class must be invoked only after provider-specific integrity checks.
 */
final readonly class StreamingFencedStateWriter
{
    public function __construct(
        private PDO $pdo,
        private MarketStateRepositoryInterface $states,
    ){}

    public function publishTrading(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,MarketState $state,
    ):void{
        if($state->sourceId->value()!==$sourceId)throw new DomainException('Fenced trading source mismatch.');
        $this->write($organizationId,$sourceId,$shard,$workerId,$fencingToken,
            fn()=> $this->states->save($organizationId,$state));
    }

    public function publishReference(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,ReferenceMarketState $state,
    ):void{
        if($state->sourceId->value()!==$sourceId)throw new DomainException('Fenced reference source mismatch.');
        $this->write($organizationId,$sourceId,$shard,$workerId,$fencingToken,
            fn()=> $this->states->saveReference($organizationId,$state));
    }

    private function write(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,callable $callback,
    ):void{
        if($organizationId===''||$sourceId===''||$shard===''||$workerId===''||$fencingToken<1){
            throw new DomainException('Invalid fenced publication identity.');
        }
        // Nested transactions are not supported: the lease lock must cover
        // the *entire* state write on the same database connection.
        if($this->pdo->inTransaction())throw new DomainException('Fenced publication requires its own transaction.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:worker AND fencing_token=:token
                   AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE'
            );
            $stmt->execute([
                'org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
                'worker'=>$workerId,'token'=>$fencingToken,
            ]);
            if($stmt->fetchColumn()===false){
                throw new DomainException('Streaming worker lost lease or fencing token.');
            }
            $callback();
            $this->pdo->commit();
        }catch(Throwable $failure){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $failure;
        }
    }
}
