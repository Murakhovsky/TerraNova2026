<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Model\PipelineDefinition;
use Domains\Sales\Model\PipelineStageDefinition;
use Domains\Sales\Model\PipelineTransitionDefinition;
use PDO;

final readonly class MysqlPipelineRepository implements PipelineRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function getPipeline(string $organizationId, string $pipelineId): ?PipelineDefinition
    {
        $row = $this->one(
            'SELECT id, organization_id, code, name, initial_stage_id
             FROM sales_pipelines
             WHERE id = :id AND organization_id = :organization_id AND status = "ACTIVE" AND initial_stage_id IS NOT NULL',
            ['id' => $pipelineId, 'organization_id' => $organizationId],
        );
        return $row ? $this->pipeline($row) : null;
    }

    public function getDefaultPipeline(string $organizationId): ?PipelineDefinition
    {
        $row = $this->one(
            'SELECT id, organization_id, code, name, initial_stage_id
             FROM sales_pipelines
             WHERE organization_id = :organization_id AND status = "ACTIVE" AND initial_stage_id IS NOT NULL
             ORDER BY is_default DESC, created_at, id LIMIT 1',
            ['organization_id' => $organizationId],
        );
        return $row ? $this->pipeline($row) : null;
    }

    public function getStage(string $organizationId, string $stageId): ?PipelineStageDefinition
    {
        $row = $this->one(
            'SELECT s.*
             FROM sales_pipeline_stages s
             INNER JOIN sales_pipelines p ON p.id = s.pipeline_id
                AND p.organization_id = :organization_id AND p.status = "ACTIVE"
             WHERE s.id = :id AND s.organization_id = :organization_id_stage AND s.status = "ACTIVE"',
            ['id' => $stageId, 'organization_id' => $organizationId, 'organization_id_stage' => $organizationId],
        );
        return $row ? $this->stage($row) : null;
    }

    public function findStageByCode(string $organizationId, string $pipelineId, string $stageCode): ?PipelineStageDefinition
    {
        $row = $this->one(
            'SELECT s.*
             FROM sales_pipeline_stages s
             INNER JOIN sales_pipelines p ON p.id = s.pipeline_id
                AND p.organization_id = :organization_id AND p.status = "ACTIVE"
             WHERE s.pipeline_id = :pipeline_id AND s.organization_id = :organization_id_stage
                AND s.code = :code AND s.status = "ACTIVE"',
            [
                'organization_id' => $organizationId,
                'organization_id_stage' => $organizationId,
                'pipeline_id' => $pipelineId,
                'code' => strtoupper($stageCode),
            ],
        );
        return $row ? $this->stage($row) : null;
    }

    public function getTransition(string $organizationId, string $pipelineId, string $fromStageId, string $toStageId): ?PipelineTransitionDefinition
    {
        $row = $this->one(
            'SELECT t.*
             FROM sales_pipeline_transitions t
             INNER JOIN sales_pipelines p ON p.id = t.pipeline_id
                AND p.organization_id = :organization_id AND p.status = "ACTIVE"
             INNER JOIN sales_pipeline_stages f ON f.id = t.from_stage_id AND f.status = "ACTIVE"
             INNER JOIN sales_pipeline_stages destination ON destination.id = t.to_stage_id AND destination.status = "ACTIVE"
             WHERE t.organization_id = :organization_id_transition AND t.pipeline_id = :pipeline_id
                AND t.from_stage_id = :from_stage_id AND t.to_stage_id = :to_stage_id',
            [
                'organization_id' => $organizationId,
                'organization_id_transition' => $organizationId,
                'pipeline_id' => $pipelineId,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
            ],
        );
        if (!$row) return null;

        return new PipelineTransitionDefinition(
            (string) $row['id'],
            (string) $row['pipeline_id'],
            (string) $row['from_stage_id'],
            (string) $row['to_stage_id'],
            json_decode((string) ($row['conditions'] ?? '[]'), true) ?: [],
            (bool) $row['requires_approval'],
        );
    }

    public function getInitialStage(string $organizationId, string $pipelineId): ?PipelineStageDefinition
    {
        return $this->getPipeline($organizationId, $pipelineId)?->initialStage();
    }

    public function isValidLostReason(string $organizationId, string $pipelineId, string $reasonId): bool
    {
        return $this->one(
            'SELECT id FROM sales_lost_reasons
             WHERE id = :id AND organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE" LIMIT 1',
            ['id' => $reasonId, 'organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        ) !== null;
    }

    public function defaultLostReasonId(string $organizationId, string $pipelineId): ?string
    {
        $row = $this->one(
            'SELECT id FROM sales_lost_reasons
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"
             ORDER BY code = "OTHER" DESC, sort_order, id LIMIT 1',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
        return $row ? (string) $row['id'] : null;
    }

    public function lostReasons(string $organizationId, string $pipelineId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, code, name, sort_order
             FROM sales_lost_reasons
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"
             ORDER BY sort_order, id'
        );
        $statement->execute(['organization_id' => $organizationId, 'pipeline_id' => $pipelineId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function pipeline(array $row): PipelineDefinition
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM sales_pipeline_stages
             WHERE pipeline_id = :pipeline_id AND organization_id = :organization_id AND status = "ACTIVE"
             ORDER BY sort_order, id'
        );
        $statement->execute(['pipeline_id' => $row['id'], 'organization_id' => $row['organization_id']]);
        $stages = array_map(fn (array $stage): PipelineStageDefinition => $this->stage($stage), $statement->fetchAll(PDO::FETCH_ASSOC));

        return new PipelineDefinition(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['code'],
            (string) $row['name'],
            $stages,
            isset($row['initial_stage_id']) ? (string) $row['initial_stage_id'] : null,
        );
    }

    private function stage(array $row): PipelineStageDefinition
    {
        return new PipelineStageDefinition(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['sort_order'],
            (bool) $row['is_terminal'],
            (bool) $row['is_won'],
            (bool) $row['is_lost'],
            (float) $row['probability_default'],
        );
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
