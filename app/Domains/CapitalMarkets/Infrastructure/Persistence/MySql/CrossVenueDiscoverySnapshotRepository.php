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

    public function mayScan(string $organizationId, int $cooldownSeconds = 90): bool
    {
        $statement=$this->connection->prepare(
            'SELECT TIMESTAMPDIFF(SECOND,MAX(scanned_at),UTC_TIMESTAMP(6))
             FROM tn_capital_market_cross_venue_discovery_snapshots WHERE organization_id=:org'
        );
        $statement->execute(['org'=>$organizationId]);
        $age=$statement->fetchColumn();
        return $age===null||$age===false||(int)$age >= $cooldownSeconds;
    }
}
