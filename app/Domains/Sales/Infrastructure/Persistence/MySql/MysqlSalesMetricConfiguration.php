<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesMetricConfigurationInterface;
use PDO;
use Throwable;

final readonly class MysqlSalesMetricConfiguration implements SalesMetricConfigurationInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function stageThresholds(string $organizationId, ?string $pipelineId = null): array
    {
        $sql = 'SELECT s.id,s.pipeline_id,s.code,t.stuck_after_seconds '
            . 'FROM sales_pipeline_stages s LEFT JOIN sales_stage_metric_thresholds t '
            . 'ON t.organization_id=s.organization_id AND t.stage_id=s.id '
            . 'WHERE s.organization_id=:organization_id';
        $params = ['organization_id' => $organizationId];
        if ($pipelineId !== null && $pipelineId !== '') {
            $sql .= ' AND s.pipeline_id=:pipeline_id';
            $params['pipeline_id'] = $pipelineId;
        }
        $sql .= ' ORDER BY s.pipeline_id,s.sort_order,s.id';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['stuck_after_seconds'] = $row['stuck_after_seconds'] !== null ? (int) $row['stuck_after_seconds'] : null;
        }
        unset($row);
        return $rows;
    }

    public function setStageStuckThreshold(
        string $organizationId,
        string $stageId,
        ?int $stuckAfterSeconds,
        string $actorId = 'system',
    ): void {
        if ($stuckAfterSeconds !== null && $stuckAfterSeconds <= 0) {
            throw new DomainException('Stuck threshold must be a positive number of seconds or null.');
        }

        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) $this->connection->beginTransaction();
        try {
            $stage = $this->stage($organizationId, $stageId);
            if ($stage === null) throw new DomainException('Stage not found.');
            $before = $this->threshold($organizationId, $stageId);

            if ($stuckAfterSeconds === null) {
                $statement = $this->connection->prepare(
                    'DELETE FROM sales_stage_metric_thresholds WHERE organization_id=:organization_id AND stage_id=:stage_id'
                );
                $statement->execute(['organization_id' => $organizationId, 'stage_id' => $stageId]);
            } else {
                $statement = $this->connection->prepare(
                    'INSERT INTO sales_stage_metric_thresholds (organization_id,stage_id,stuck_after_seconds,updated_at) '
                    . 'VALUES (:organization_id,:stage_id,:threshold,NOW(6)) '
                    . 'ON DUPLICATE KEY UPDATE stuck_after_seconds=VALUES(stuck_after_seconds),updated_at=VALUES(updated_at)'
                );
                $statement->execute([
                    'organization_id' => $organizationId,
                    'stage_id' => $stageId,
                    'threshold' => $stuckAfterSeconds,
                ]);
            }

            $after = $this->threshold($organizationId, $stageId);
            $revision = $this->connection->prepare(
                'INSERT INTO cos_configuration_revisions '
                . '(organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at) '
                . 'VALUES (:organization_id,"sales","STAGE",:entity_id,1,"UPDATE","USER",:actor_id,NULL,:before_payload,:after_payload,NOW(6))'
            );
            $revision->execute([
                'organization_id' => $organizationId,
                'entity_id' => 'metric-threshold:' . $stageId,
                'actor_id' => $actorId,
                'before_payload' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after_payload' => json_encode($after ?? ['stage_id' => $stageId, 'stuck_after_seconds' => null], JSON_THROW_ON_ERROR),
            ]);
            if ($ownsTransaction) $this->connection->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    private function stage(string $organizationId, string $stageId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,pipeline_id,code FROM sales_pipeline_stages WHERE organization_id=:organization_id AND id=:stage_id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'stage_id' => $stageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function threshold(string $organizationId, string $stageId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT organization_id,stage_id,stuck_after_seconds,updated_at FROM sales_stage_metric_thresholds '
            . 'WHERE organization_id=:organization_id AND stage_id=:stage_id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'stage_id' => $stageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['stuck_after_seconds'] = (int) $row['stuck_after_seconds'];
        return $row;
    }
}
