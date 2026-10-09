<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use PDO;
use RuntimeException;

final readonly class CrossVenueQuoteSnapshotRepository
{
    public function __construct(private PDO $connection) {}

    /** @param array<string,mixed> $snapshot */
    public function record(string $organizationId, int $actorId, array $snapshot): void
    {
        if($organizationId===''||$actorId<1||($snapshot['dataset']??null)!=='cross_venue_quote_observation')
            throw new RuntimeException('Invalid quote evidence.');
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_cross_venue_quote_snapshots
             (organization_id,actor_id,scanned_at,candidate_count,source_health_json,results_json)
             VALUES (:org,:actor,UTC_TIMESTAMP(6),:count,:health,:result)'
        );
        $statement->execute([
            'org'=>$organizationId,'actor'=>$actorId,
            'count'=>count($snapshot['rows']??[]),
            'health'=>json_encode($snapshot['source_health'],JSON_THROW_ON_ERROR),
            'result'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function latest(string $organizationId): ?array
    {
        $statement=$this->connection->prepare(
            'SELECT scanned_at,actor_id,results_json FROM tn_capital_market_cross_venue_quote_snapshots
             WHERE organization_id=:org ORDER BY scanned_at DESC,id DESC LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $data=json_decode((string)$row['results_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new RuntimeException('Quote evidence is corrupt.');
        $data['stored_at_utc']=$row['scanned_at'];
        return $data;
    }

    public function reserve(string $organizationId,int $actorId,int $cooldownSeconds=60):bool
    {
        if($organizationId===''||$actorId<1||$cooldownSeconds<60)throw new RuntimeException('Invalid quote scan reservation.');
        $this->connection->prepare(
            'INSERT IGNORE INTO tn_capital_market_quote_scan_gates
             (organization_id,actor_id,last_attempt_at)
             VALUES (:org,:actor,\'1970-01-01 00:00:00\')'
        )->execute(['org'=>$organizationId,'actor'=>$actorId]);
        $statement=$this->connection->prepare(
            'UPDATE tn_capital_market_quote_scan_gates
             SET actor_id=:actor,last_attempt_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org
               AND TIMESTAMPDIFF(SECOND,last_attempt_at,UTC_TIMESTAMP(6))>=:seconds'
        );
        $statement->execute(['org'=>$organizationId,'actor'=>$actorId,'seconds'=>$cooldownSeconds]);
        return $statement->rowCount()===1;
    }
}
