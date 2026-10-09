<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class CrossVenueDiscoverySnapshotRepository
{
    public function __construct(private PDO $connection) {}

    /** @param array<string,mixed> $snapshot */
    public function record(string $organizationId, int $actorId, array $snapshot): void
    {
        if ($organizationId === '' || $actorId < 1 || ($snapshot['dataset']??null)!=='cross_venue_candidate_discovery') {
            throw new RuntimeException('Invalid discovery persistence request.');
        }
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_cross_venue_discovery_snapshots
             (organization_id,actor_id,universe_id,scanned_at,candidate_count,source_health_json,results_json)
             VALUES (:org,:actor,:universe,UTC_TIMESTAMP(6),:count,:health,:data)'
        );
        $statement->execute([
            'org'=>$organizationId,'actor'=>$actorId,'universe'=>(string)$snapshot['universe'],
            'count'=>(int)$snapshot['total'],
            'health'=>json_encode($snapshot['source_health'],JSON_THROW_ON_ERROR),
            'data'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function latest(string $organizationId): ?array
    {
        $statement=$this->connection->prepare(
            'SELECT scanned_at,actor_id,results_json
             FROM tn_capital_market_cross_venue_discovery_snapshots
             WHERE organization_id=:org ORDER BY scanned_at DESC,id DESC LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $result=json_decode((string)$row['results_json'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($result)||array_is_list($result))throw new RuntimeException('Stored discovery evidence is corrupt.');
        $result['stored_at_utc']=$row['scanned_at'];
        $result['operator_id']=(int)$row['actor_id'];
        return $result;
    }

    /**
     * Atomic reservation BEFORE external calls. A failed scan still consumes
     * the cooldown so concurrent browser POSTs cannot exceed provider budgets.
     * InnoDB serializes competing UPDATEs through the organization primary key.
     */
    public function reserveScan(string $organizationId, int $actorId, int $cooldownSeconds = 90): bool
    {
        if ($organizationId === '' || $actorId < 1 || $cooldownSeconds < 60) {
            throw new RuntimeException('Invalid scan reservation.');
        }
        $this->connection->prepare(
            'INSERT IGNORE INTO tn_capital_market_discovery_scan_gates
             (organization_id,actor_id,last_attempt_at)
             VALUES (:org,:actor,\'1970-01-01 00:00:00\')'
        )->execute(['org'=>$organizationId,'actor'=>$actorId]);

        $statement=$this->connection->prepare(
            'UPDATE tn_capital_market_discovery_scan_gates
             SET last_attempt_at=UTC_TIMESTAMP(6),actor_id=:actor
             WHERE organization_id=:org
               AND TIMESTAMPDIFF(SECOND,last_attempt_at,UTC_TIMESTAMP(6))>=:cooldown'
        );
        $statement->execute(['org'=>$organizationId,'actor'=>$actorId,'cooldown'=>$cooldownSeconds]);
        return $statement->rowCount() === 1;
    }
}
