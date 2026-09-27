<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthSignalPollingHealthRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthSignalPollingHealthRepository implements GrowthSignalPollingHealthRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function state(string $organizationId,string $collectorName): ?array
    {
        return $this->one(
            'SELECT organization_id,collector_name,status,consecutive_failures,last_run_status,last_success_at,
                    last_failure_at,next_retry_at,error_summary,updated_at
             FROM tn_growth_signal_collector_health
             WHERE organization_id=:organization_id AND collector_name=:collector_name
             LIMIT 1',
            ['organization_id'=>$organizationId,'collector_name'=>$collectorName],
        );
    }

    public function statesForOrganization(string $organizationId): array
    {
        $statement=$this->connection->prepare(
            'SELECT collector_name,status,consecutive_failures,last_run_status,last_success_at,
                    last_failure_at,next_retry_at,error_summary,updated_at
             FROM tn_growth_signal_collector_health
             WHERE organization_id=:organization_id
             ORDER BY collector_name'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['consecutive_failures']=(int)$row['consecutive_failures'];
        unset($row);
        return $rows;
    }

    public function markHealthy(string $organizationId,string $collectorName,DateTimeImmutable $at): void
    {
        $this->execute(
            'INSERT INTO tn_growth_signal_collector_health
             (organization_id,collector_name,status,consecutive_failures,last_run_status,last_success_at,last_failure_at,next_retry_at,error_summary)
             VALUES(:organization_id,:collector_name,\'healthy\',0,\'completed\',:at,NULL,NULL,NULL)
             ON DUPLICATE KEY UPDATE
               status=\'healthy\',consecutive_failures=0,last_run_status=\'completed\',last_success_at=:at2,
               next_retry_at=NULL,error_summary=NULL,updated_at=NOW(6)',
            [
                'organization_id'=>$organizationId,'collector_name'=>$collectorName,
                'at'=>$at->format('Y-m-d H:i:s.u'),'at2'=>$at->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    public function markDegraded(
        string $organizationId,
        string $collectorName,
        DateTimeImmutable $at,
        ?string $errorSummary,
    ): void {
        $summary=$errorSummary===null?null:mb_substr(trim($errorSummary),0,2000);
        $this->execute(
            'INSERT INTO tn_growth_signal_collector_health
             (organization_id,collector_name,status,consecutive_failures,last_run_status,last_success_at,last_failure_at,next_retry_at,error_summary)
             VALUES(:organization_id,:collector_name,\'degraded\',0,\'partial\',:at,NULL,NULL,:error_summary)
             ON DUPLICATE KEY UPDATE
               status=\'degraded\',consecutive_failures=0,last_run_status=\'partial\',last_success_at=:at2,
               next_retry_at=NULL,error_summary=:error_summary2,updated_at=NOW(6)',
            [
                'organization_id'=>$organizationId,'collector_name'=>$collectorName,
                'at'=>$at->format('Y-m-d H:i:s.u'),'at2'=>$at->format('Y-m-d H:i:s.u'),
                'error_summary'=>$summary,'error_summary2'=>$summary,
            ],
        );
    }

    public function markFailed(
        string $organizationId,
        string $collectorName,
        DateTimeImmutable $at,
        DateTimeImmutable $nextRetryAt,
        string $errorSummary,
    ): void {
        $summary=mb_substr(trim($errorSummary),0,2000);
        if($summary==='')throw new InvalidArgumentException('Growth polling failure summary is required.');
        if($nextRetryAt<=$at)throw new InvalidArgumentException('Growth polling retry must be after failure time.');

        $this->execute(
            'INSERT INTO tn_growth_signal_collector_health
             (organization_id,collector_name,status,consecutive_failures,last_run_status,last_success_at,last_failure_at,next_retry_at,error_summary)
             VALUES(:organization_id,:collector_name,\'cooling_down\',1,\'failed\',NULL,:at,:next_retry_at,:error_summary)
             ON DUPLICATE KEY UPDATE
               status=\'cooling_down\',consecutive_failures=consecutive_failures+1,last_run_status=\'failed\',
               last_failure_at=:at2,next_retry_at=:next_retry_at2,error_summary=:error_summary2,updated_at=NOW(6)',
            [
                'organization_id'=>$organizationId,'collector_name'=>$collectorName,
                'at'=>$at->format('Y-m-d H:i:s.u'),'at2'=>$at->format('Y-m-d H:i:s.u'),
                'next_retry_at'=>$nextRetryAt->format('Y-m-d H:i:s.u'),
                'next_retry_at2'=>$nextRetryAt->format('Y-m-d H:i:s.u'),
                'error_summary'=>$summary,'error_summary2'=>$summary,
            ],
        );
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['consecutive_failures']=(int)$row['consecutive_failures'];
        return $row;
    }
}
