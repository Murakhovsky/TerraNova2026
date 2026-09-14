<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesPipelineGovernanceInterface;
use PDO;
use Throwable;

final readonly class MysqlSalesPipelineGovernance implements SalesPipelineGovernanceInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function cloneToDraft(
        string $organizationId,
        string $sourcePipelineId,
        array $input,
        string $actorId,
    ): array {
        return $this->transactional(function () use ($organizationId, $sourcePipelineId, $input, $actorId): array {
            $source = $this->requiredPipeline($organizationId, $sourcePipelineId);
            $code = strtolower(trim((string) ($input['code'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9-]{1,99}$/', $code) || $name === '') {
                throw new DomainException('Valid clone code and name are required.');
            }

            $pipelineId = bin2hex(random_bytes(16));
            $insertPipeline = $this->connection->prepare(
                'INSERT INTO sales_pipelines
                    (id, organization_id, code, name, is_default, initial_stage_id, status, configuration_version)
                 VALUES (:id, :organization_id, :code, :name, 0, NULL, "DRAFT", 1)'
            );
            $insertPipeline->execute([
                'id' => $pipelineId,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => mb_substr($name, 0, 180),
            ]);

            $sourceStages = $this->all(
                'SELECT * FROM sales_pipeline_stages
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"
                 ORDER BY sort_order, id',
                ['organization_id' => $organizationId, 'pipeline_id' => $sourcePipelineId],
            );
            if ($sourceStages === []) throw new DomainException('Source pipeline has no active stages to clone.');

            $stageMap = [];
            $insertStage = $this->connection->prepare(
                'INSERT INTO sales_pipeline_stages
                    (id, organization_id, pipeline_id, code, name, sort_order, is_terminal, is_won, is_lost,
                     probability_default, status, configuration_version)
                 VALUES (:id, :organization_id, :pipeline_id, :code, :name, :sort_order, :is_terminal, :is_won, :is_lost,
                         :probability_default, "ACTIVE", 1)'
            );
            foreach ($sourceStages as $stage) {
                $stageId = bin2hex(random_bytes(16));
                $stageMap[(string) $stage['id']] = $stageId;
                $insertStage->execute([
                    'id' => $stageId,
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'code' => (string) $stage['code'],
                    'name' => (string) $stage['name'],
                    'sort_order' => (int) $stage['sort_order'],
                    'is_terminal' => (int) $stage['is_terminal'],
                    'is_won' => (int) $stage['is_won'],
                    'is_lost' => (int) $stage['is_lost'],
                    'probability_default' => (float) $stage['probability_default'],
                ]);
            }

            $sourceInitialStageId = (string) ($source['initial_stage_id'] ?? '');
            $initialStageId = $stageMap[$sourceInitialStageId] ?? null;
            if ($initialStageId !== null) {
                $setInitial = $this->connection->prepare(
                    'UPDATE sales_pipelines SET initial_stage_id = :initial_stage_id
                     WHERE organization_id = :organization_id AND id = :pipeline_id'
                );
                $setInitial->execute([
                    'initial_stage_id' => $initialStageId,
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                ]);
            }

            $sourceTransitions = $this->all(
                'SELECT * FROM sales_pipeline_transitions
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id
                 ORDER BY from_stage_id, to_stage_id',
                ['organization_id' => $organizationId, 'pipeline_id' => $sourcePipelineId],
            );
            $insertTransition = $this->connection->prepare(
                'INSERT INTO sales_pipeline_transitions
                    (id, organization_id, pipeline_id, from_stage_id, to_stage_id, requires_approval, conditions)
                 VALUES (:id, :organization_id, :pipeline_id, :from_stage_id, :to_stage_id, :requires_approval, :conditions)'
            );
            $transitionCount = 0;
            foreach ($sourceTransitions as $transition) {
                $from = $stageMap[(string) $transition['from_stage_id']] ?? null;
                $to = $stageMap[(string) $transition['to_stage_id']] ?? null;
                if ($from === null || $to === null) continue;
                $insertTransition->execute([
                    'id' => bin2hex(random_bytes(16)),
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'from_stage_id' => $from,
                    'to_stage_id' => $to,
                    'requires_approval' => (int) $transition['requires_approval'],
                    'conditions' => (string) ($transition['conditions'] ?? '[]'),
                ]);
                $transitionCount++;
            }

            $sourceReasons = $this->all(
                'SELECT * FROM sales_lost_reasons
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"
                 ORDER BY sort_order, id',
                ['organization_id' => $organizationId, 'pipeline_id' => $sourcePipelineId],
            );
            if ($sourceReasons === []) {
                $sourceReasons = [[
                    'code' => 'OTHER',
                    'name' => 'Інше',
                    'sort_order' => 900,
                ]];
            }
            $insertReason = $this->connection->prepare(
                'INSERT INTO sales_lost_reasons
                    (id, organization_id, pipeline_id, code, name, sort_order, status, configuration_version)
                 VALUES (:id, :organization_id, :pipeline_id, :code, :name, :sort_order, "ACTIVE", 1)'
            );
            foreach ($sourceReasons as $reason) {
                $insertReason->execute([
                    'id' => bin2hex(random_bytes(16)),
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'code' => (string) $reason['code'],
                    'name' => (string) $reason['name'],
                    'sort_order' => (int) $reason['sort_order'],
                ]);
            }

            $after = $this->requiredPipeline($organizationId, $pipelineId);
            $summary = [
                'pipeline' => $after,
                'source_pipeline_id' => $sourcePipelineId,
                'cloned_stages' => count($stageMap),
                'cloned_transitions' => $transitionCount,
                'cloned_lost_reasons' => count($sourceReasons),
            ];
            $this->revision(
                $organizationId,
                $pipelineId,
                $actorId,
                'Cloned from pipeline ' . $sourcePipelineId . ' for safe draft editing.',
                $summary,
            );
            return $summary;
        });
    }

    public function revisions(string $organizationId, string $pipelineId, int $limit = 100): array
    {
        $this->requiredPipeline($organizationId, $pipelineId);
        $limit = max(1, min($limit, 200));
        $rows = $this->all(
            'SELECT r.*
             FROM cos_configuration_revisions r
             WHERE r.organization_id = :revision_org AND r.domain_name = "sales" AND (
                (r.configuration_type IN ("PIPELINE", "TRANSITION") AND r.entity_id = :pipeline_entity)
                OR (r.configuration_type = "STAGE" AND EXISTS (
                    SELECT 1 FROM sales_pipeline_stages s
                    WHERE s.organization_id = :stage_org AND s.pipeline_id = :stage_pipeline AND s.id = r.entity_id
                ))
                OR (r.configuration_type = "LOST_REASON" AND EXISTS (
                    SELECT 1 FROM sales_lost_reasons reason
                    WHERE reason.organization_id = :reason_org AND reason.pipeline_id = :reason_pipeline AND reason.id = r.entity_id
                ))
             )
             ORDER BY r.id DESC
             LIMIT ' . $limit,
            [
                'revision_org' => $organizationId,
                'pipeline_entity' => $pipelineId,
                'stage_org' => $organizationId,
                'stage_pipeline' => $pipelineId,
                'reason_org' => $organizationId,
                'reason_pipeline' => $pipelineId,
            ],
        );
        foreach ($rows as &$row) {
            $row['before_payload'] = $this->decodeJson($row['before_payload'] ?? null);
            $row['after_payload'] = $this->decodeJson($row['after_payload'] ?? null);
        }
        unset($row);
        return $rows;
    }

    private function revision(
        string $organizationId,
        string $pipelineId,
        string $actorId,
        string $reason,
        array $after,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions
                (organization_id, domain_name, configuration_type, entity_id, entity_version, action,
                 actor_type, actor_id, reason, before_payload, after_payload, created_at)
             VALUES (:organization_id, "sales", "PIPELINE", :entity_id, 1, "PROVISION",
                     "USER", :actor_id, :reason, NULL, :after_payload, NOW(6))'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'entity_id' => $pipelineId,
            'actor_id' => $actorId,
            'reason' => mb_substr($reason, 0, 500),
            'after_payload' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }

    private function requiredPipeline(string $organizationId, string $pipelineId): array
    {
        return $this->one(
            'SELECT * FROM sales_pipelines WHERE organization_id = :organization_id AND id = :pipeline_id LIMIT 1',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        ) ?? throw new DomainException('Pipeline not found.');
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function transactional(callable $callback): mixed
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) $this->connection->beginTransaction();
        try {
            $result = $callback();
            if ($ownsTransaction) $this->connection->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function all(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
