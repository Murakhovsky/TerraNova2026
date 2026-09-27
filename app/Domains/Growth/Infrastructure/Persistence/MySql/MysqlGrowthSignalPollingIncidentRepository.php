<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthSignalPollingIncidentRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthSignalPollingIncidentRepository implements GrowthSignalPollingIncidentRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function openIncident(string $organizationId,string $collectorName): ?array
    {
        return $this->one(
            'SELECT organization_id,incident_id,collector_name,status,failure_count,opened_at,last_failure_at,
                    next_retry_at,error_summary,resolved_at,updated_at
             FROM tn_growth_signal_collector_incidents
             WHERE organization_id=:organization_id AND collector_name=:collector_name AND status=\'open\'
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'collector_name'=>$collectorName],
        );
    }

    public function activeIncidents(string $organizationId): array
    {
        $statement=$this->connection->prepare(
            'SELECT incident_id,collector_name,status,failure_count,opened_at,last_failure_at,
                    next_retry_at,error_summary,updated_at
             FROM tn_growth_signal_collector_incidents
             WHERE organization_id=:organization_id AND status=\'open\'
             ORDER BY opened_at DESC,incident_id DESC'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['failure_count']=(int)$row['failure_count'];
        unset($row);
        return $rows;
    }

    public function upsertOpen(
        string $organizationId,
        string $incidentId,
        string $collectorName,
        int $failureCount,
        string $errorSummary,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $lastFailureAt,
        DateTimeImmutable $nextRetryAt,
    ): array {
        if($failureCount<1)throw new InvalidArgumentException('Growth collector incident failure count must be positive.');
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_signal_collector_incidents
             (organization_id,incident_id,collector_name,status,failure_count,opened_at,last_failure_at,next_retry_at,error_summary)
             VALUES(:organization_id,:incident_id,:collector_name,\'open\',:failure_count,:opened_at,:last_failure_at,:next_retry_at,:error_summary)
             ON DUPLICATE KEY UPDATE
               failure_count=GREATEST(failure_count,VALUES(failure_count)),
               last_failure_at=VALUES(last_failure_at),
               next_retry_at=VALUES(next_retry_at),
               error_summary=VALUES(error_summary),
               updated_at=NOW(6)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'incident_id'=>$incidentId,'collector_name'=>$collectorName,
            'failure_count'=>$failureCount,'opened_at'=>$openedAt->format('Y-m-d H:i:s.u'),
            'last_failure_at'=>$lastFailureAt->format('Y-m-d H:i:s.u'),
            'next_retry_at'=>$nextRetryAt->format('Y-m-d H:i:s.u'),
            'error_summary'=>mb_substr($errorSummary,0,2000),
        ]);
        return $this->openIncident($organizationId,$collectorName)
            ?? throw new InvalidArgumentException('Growth collector incident could not be read back.');
    }

    public function resolveOpen(string $organizationId,string $collectorName,DateTimeImmutable $resolvedAt): ?array
    {
        $open=$this->openIncident($organizationId,$collectorName);
        if($open===null)return null;
        $incidentId=(string)$open['incident_id'];

        $statement=$this->connection->prepare(
            'UPDATE tn_growth_signal_collector_incidents
             SET status=\'resolved\',resolved_at=:resolved_at,next_retry_at=NULL,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND incident_id=:incident_id AND status=\'open\''
        );
        $statement->execute([
            'resolved_at'=>$resolvedAt->format('Y-m-d H:i:s.u'),
            'organization_id'=>$organizationId,'incident_id'=>$incidentId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth collector incident resolution raced or failed.');

        $row=$this->one(
            'SELECT organization_id,incident_id,collector_name,status,failure_count,opened_at,last_failure_at,
                    next_retry_at,error_summary,resolved_at,updated_at
             FROM tn_growth_signal_collector_incidents
             WHERE organization_id=:organization_id AND incident_id=:incident_id LIMIT 1',
            ['organization_id'=>$organizationId,'incident_id'=>$incidentId],
        );
        if($row!==null)$row['failure_count']=(int)$row['failure_count'];
        return $row;
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['failure_count']=(int)$row['failure_count'];
        return $row;
    }
}
