<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use PDO;

/**
 * Streaming-only raw -> canonical ingest guarded by a held DB lease row.
 * All repositories and TransactionManager MUST share the same PDO connection.
 * TransactionManager owns commit/afterCommit callbacks, not this gateway.
 */
final readonly class StreamingFencedIngestionGateway
{
    public function __construct(
        private PDO $pdo,
        private TransactionManagerInterface $transactions,
        private MarketSourceRepositoryInterface $sources,
        private MarketDataIngestionInterface $ingestion,
        private MarketClockInterface $clock,
    ){}

    public function ingest(
        string $organizationId,string $sourceId,string $shard,
        string $workerId,int $fencingToken,RawMarketEvent $raw,
    ):MarketDataIngestionResult{
        if($raw->sourceId->value()!==$sourceId||$fencingToken<1){
            throw new DomainException('Streaming ingestion source/fencing mismatch.');
        }
        if($this->pdo->inTransaction()){
            throw new DomainException('Streaming ingestion requires an outer transaction boundary.');
        }
        return $this->transactions->transactional(function()use(
            $organizationId,$sourceId,$shard,$workerId,$fencingToken,$raw,
        ):MarketDataIngestionResult{
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
            if($stmt->fetchColumn()===false)throw new DomainException('Streaming ingestion lease expired or superseded.');
            $previous=$this->sources->health($organizationId,$raw->sourceId);
            $this->sources->saveHealth($organizationId,new MarketSourceHealth(
                $raw->sourceId,MarketConnectionState::Active,$raw->receivedAt,
                $this->clock->now(),$previous?->failureCount??0,
                $previous?->queueLag??0,$this->clock->reliable(),
                null,$previous?->messagesPerSecond,$previous?->lastLatencyMilliseconds,
                $previous?->errorCount??0,$previous?->reconnectCount??0,
                $previous?->rateLimitState??\Domains\CapitalMarkets\Domain\MarketData\MarketRateLimitState::Unknown,
            ));
            return $this->ingestion->ingest($organizationId,$raw);
        });
    }
}
