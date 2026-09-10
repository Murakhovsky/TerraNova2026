<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use PDO;
use Throwable;

final readonly class MysqlSalesPipelineAdministration implements SalesPipelineAdministrationInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function pipelines(string $organizationId): array
    {
        return $this->all(
            'SELECT p.*, initial_stage.code AS initial_stage_code,
                (SELECT COUNT(*) FROM sales_pipeline_stages s WHERE s.pipeline_id = p.id AND s.organization_id = p.organization_id AND s.status = "ACTIVE") AS stage_count,
                (SELECT COUNT(*) FROM tn_client_cases c WHERE c.organization_id = p.organization_id AND c.pipeline_id = p.id AND c.status IN ("active", "paused")) AS active_deals
             FROM sales_pipelines p
             LEFT JOIN sales_pipeline_stages initial_stage ON initial_stage.id = p.initial_stage_id
             WHERE p.organization_id = :organization_id
             ORDER BY p.is_default DESC, p.status = "ACTIVE" DESC, p.created_at, p.id',
            ['organization_id' => $organizationId],
        );
    }

    public function pipeline(string $organizationId, string $pipelineId): ?array
    {
        $pipeline = $this->pipelineRow($organizationId, $pipelineId);
        if ($pipeline === null) return null;

        $pipeline['stages'] = $this->all(
            'SELECT s.*,
                (SELECT COUNT(*) FROM tn_client_cases c WHERE c.organization_id = s.organization_id AND c.stage_id = s.id AND c.status IN ("active", "paused")) AS active_deals
             FROM sales_pipeline_stages s
             WHERE s.organization_id = :organization_id AND s.pipeline_id = :pipeline_id
             ORDER BY s.sort_order, s.id',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
        $pipeline['transitions'] = $this->transitions($organizationId, $pipelineId);
        $pipeline['lost_reasons'] = $this->lostReasons($organizationId, $pipelineId);
        $pipeline['validation'] = $this->validatePipeline($organizationId, $pipelineId);
        return $pipeline;
    }

    public function createPipeline(string $organizationId, array $input, string $actorId): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9-]{1,99}$/', $code) || $name === '') {
            throw new DomainException('Valid pipeline code and name are required.');
        }

        return $this->transactional(function () use ($organizationId, $code, $name, $actorId): array {
            $id = bin2hex(random_bytes(16));
            $statement = $this->connection->prepare(
                'INSERT INTO sales_pipelines
                    (id, organization_id, code, name, is_default, initial_stage_id, status, configuration_version)
                 VALUES (:id, :organization_id, :code, :name, 0, NULL, "DRAFT", 1)'
            );
            $statement->execute([
                'id' => $id,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => mb_substr($name, 0, 180),
            ]);
            $after = $this->requiredPipelineRow($organizationId, $id);
            $this->revision($organizationId, 'PIPELINE', $id, 1, 'CREATE', $actorId, null, $after);
            return $after;
        });
    }

    public function updatePipeline(string $organizationId, string $pipelineId, array $input, string $actorId): array
    {
        return $this->transactional(function () use ($organizationId, $pipelineId, $input, $actorId): array {
            $before = $this->requiredPipelineRow($organizationId, $pipelineId);
            $version = (int) ($input['version'] ?? 0);
            $this->assertVersion($version, (int) $before['configuration_version']);

            if (isset($input['code']) && strtolower(trim((string) $input['code'])) !== (string) $before['code']) {
                throw new DomainException('Pipeline code is immutable. Create a new pipeline instead.');
            }

            $name = mb_substr(trim((string) ($input['name'] ?? $before['name'])), 0, 180);
            $status = strtoupper(trim((string) ($input['status'] ?? $before['status'])));
            if ($name === '' || !in_array($status, ['DRAFT', 'ACTIVE', 'DISABLED', 'ARCHIVED'], true)) {
                throw new DomainException('Invalid pipeline configuration.');
            }

            $initialStageId = array_key_exists('initial_stage_id', $input)
                ? trim((string) $input['initial_stage_id'])
                : (string) ($before['initial_stage_id'] ?? '');
            $initialStageId = $initialStageId === '' ? null : $initialStageId;
            if ($initialStageId !== null && !$this->isActivePipelineStage($organizationId, $pipelineId, $initialStageId)) {
                throw new DomainException('Initial stage must be an active stage of this pipeline.');
            }

            if ($status !== 'ACTIVE' && $this->activeDealCount($organizationId, $pipelineId) > 0) {
                throw new DomainException('Pipeline has active deals and cannot be disabled, archived or moved back to draft.');
            }

            $isDefault = array_key_exists('is_default', $input) ? !empty($input['is_default']) : (bool) $before['is_default'];
            if ($isDefault) {
                $clear = $this->connection->prepare(
                    'UPDATE sales_pipelines SET is_default = 0
                     WHERE organization_id = :organization_id AND id <> :pipeline_id AND is_default = 1'
                );
                $clear->execute(['organization_id' => $organizationId, 'pipeline_id' => $pipelineId]);
            }

            $statement = $this->connection->prepare(
                'UPDATE sales_pipelines
                 SET name = :name, status = :status, initial_stage_id = :initial_stage_id,
                     is_default = :is_default, configuration_version = configuration_version + 1
                 WHERE organization_id = :organization_id AND id = :pipeline_id AND configuration_version = :version'
            );
            $statement->execute([
                'name' => $name,
                'status' => $status,
                'initial_stage_id' => $initialStageId,
                'is_default' => $isDefault ? 1 : 0,
                'organization_id' => $organizationId,
                'pipeline_id' => $pipelineId,
                'version' => $version,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');

            if ($status === 'ACTIVE') $this->assertPipelineValid($organizationId, $pipelineId);

            $after = $this->requiredPipelineRow($organizationId, $pipelineId);
            $action = match (true) {
                $status === 'ARCHIVED' && $before['status'] !== 'ARCHIVED' => 'ARCHIVE',
                $status === 'DISABLED' && $before['status'] !== 'DISABLED' => 'DISABLE',
                $status === 'ACTIVE' && $before['status'] !== 'ACTIVE' => 'ENABLE',
                default => 'UPDATE',
            };
            $this->revision($organizationId, 'PIPELINE', $pipelineId, (int) $after['configuration_version'], $action, $actorId, $before, $after);
            return $after;
        });
    }

    public function createStage(string $organizationId, string $pipelineId, array $input, string $actorId): array
    {
        return $this->transactional(function () use ($organizationId, $pipelineId, $input, $actorId): array {
            $pipeline = $this->requiredPipelineRow($organizationId, $pipelineId);
            if ($pipeline['status'] === 'ARCHIVED') throw new DomainException('Archived pipeline cannot be edited.');
            $this->assertVersion((int) ($input['pipeline_version'] ?? 0), (int) $pipeline['configuration_version']);

            $code = strtoupper(trim((string) ($input['code'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            $terminalType = strtoupper(trim((string) ($input['terminal_type'] ?? 'NONE')));
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,99}$/', $code) || $name === '' || !in_array($terminalType, ['NONE', 'WON', 'LOST'], true)) {
                throw new DomainException('Valid immutable stage code, name and terminal type are required.');
            }

            $id = bin2hex(random_bytes(16));
            $statement = $this->connection->prepare(
                'INSERT INTO sales_pipeline_stages
                    (id, organization_id, pipeline_id, code, name, sort_order, is_terminal, is_won, is_lost, probability_default, status, configuration_version)
                 VALUES (:id, :organization_id, :pipeline_id, :code, :name, :sort_order, :is_terminal, :is_won, :is_lost, :probability, "ACTIVE", 1)'
            );
            $statement->execute([
                'id' => $id,
                'organization_id' => $organizationId,
                'pipeline_id' => $pipelineId,
                'code' => $code,
                'name' => mb_substr($name, 0, 180),
                'sort_order' => (int) ($input['sort_order'] ?? 100),
                'is_terminal' => $terminalType === 'NONE' ? 0 : 1,
                'is_won' => $terminalType === 'WON' ? 1 : 0,
                'is_lost' => $terminalType === 'LOST' ? 1 : 0,
                'probability' => $this->probability($input['probability_default'] ?? 0),
            ]);
            $this->bumpPipelineVersion($organizationId, $pipelineId, (int) $pipeline['configuration_version']);
            if ($pipeline['status'] === 'ACTIVE') $this->assertPipelineValid($organizationId, $pipelineId);

            $after = $this->requiredStageRow($organizationId, $id);
            $this->revision($organizationId, 'STAGE', $id, 1, 'CREATE', $actorId, null, $after);
            return $after;
        });
    }

    public function updateStage(string $organizationId, string $stageId, array $input, string $actorId): array
    {
        return $this->transactional(function () use ($organizationId, $stageId, $input, $actorId): array {
            $before = $this->requiredStageRow($organizationId, $stageId);
            $pipelineId = (string) $before['pipeline_id'];
            $pipeline = $this->requiredPipelineRow($organizationId, $pipelineId);
            if ($pipeline['status'] === 'ARCHIVED') throw new DomainException('Archived pipeline cannot be edited.');
            $version = (int) ($input['version'] ?? 0);
            $this->assertVersion($version, (int) $before['configuration_version']);

            if (isset($input['code']) && strtoupper(trim((string) $input['code'])) !== (string) $before['code']) {
                throw new DomainException('Stage code is immutable. Create a new stage and migrate deals instead.');
            }

            $status = strtoupper(trim((string) ($input['status'] ?? $before['status'])));
            $terminalType = strtoupper(trim((string) ($input['terminal_type'] ?? $this->terminalType($before))));
            if (!in_array($status, ['ACTIVE', 'ARCHIVED'], true) || !in_array($terminalType, ['NONE', 'WON', 'LOST'], true)) {
                throw new DomainException('Invalid stage configuration.');
            }
            if ($status === 'ARCHIVED') {
                if ((string) ($pipeline['initial_stage_id'] ?? '') === $stageId) {
                    throw new DomainException('Initial stage cannot be archived. Select another initial stage first.');
                }
                if ($this->activeDealsAtStage($organizationId, $stageId) > 0) {
                    throw new DomainException('Stage has active deals. Move them with ChangeDealStage before archiving.');
                }
            }

            $name = mb_substr(trim((string) ($input['name'] ?? $before['name'])), 0, 180);
            if ($name === '') throw new DomainException('Stage name is required.');

            $statement = $this->connection->prepare(
                'UPDATE sales_pipeline_stages
                 SET name = :name, probability_default = :probability, status = :status,
                     is_terminal = :is_terminal, is_won = :is_won, is_lost = :is_lost,
                     configuration_version = configuration_version + 1
                 WHERE organization_id = :organization_id AND id = :stage_id AND configuration_version = :version'
            );
            $statement->execute([
                'name' => $name,
                'probability' => $this->probability($input['probability_default'] ?? $before['probability_default']),
                'status' => $status,
                'is_terminal' => $terminalType === 'NONE' ? 0 : 1,
                'is_won' => $terminalType === 'WON' ? 1 : 0,
                'is_lost' => $terminalType === 'LOST' ? 1 : 0,
                'organization_id' => $organizationId,
                'stage_id' => $stageId,
                'version' => $version,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');

            if ($status === 'ARCHIVED') {
                $delete = $this->connection->prepare(
                    'DELETE FROM sales_pipeline_transitions
                     WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id
                       AND (from_stage_id = :from_stage_id OR to_stage_id = :to_stage_id)'
                );
                $delete->execute([
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'from_stage_id' => $stageId,
                    'to_stage_id' => $stageId,
                ]);
            }

            $this->bumpPipelineVersion($organizationId, $pipelineId, (int) $pipeline['configuration_version']);
            if ($pipeline['status'] === 'ACTIVE') $this->assertPipelineValid($organizationId, $pipelineId);

            $after = $this->requiredStageRow($organizationId, $stageId);
            $this->revision(
                $organizationId,
                'STAGE',
                $stageId,
                (int) $after['configuration_version'],
                $status === 'ARCHIVED' && $before['status'] !== 'ARCHIVED' ? 'ARCHIVE' : 'UPDATE',
                $actorId,
                $before,
                $after,
            );
            return $after;
        });
    }

    public function reorderStages(string $organizationId, string $pipelineId, array $items, int $version, string $actorId): void
    {
        $this->transactional(function () use ($organizationId, $pipelineId, $items, $version, $actorId): void {
            $before = $this->requiredPipelineRow($organizationId, $pipelineId);
            $this->assertVersion($version, (int) $before['configuration_version']);
            $active = $this->all(
                'SELECT id FROM sales_pipeline_stages
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE" ORDER BY sort_order, id',
                ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
            );
            $expected = array_map('strval', array_column($active, 'id'));
            $provided = array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $items);
            if (count($provided) !== count(array_unique($provided)) || array_diff($expected, $provided) !== [] || array_diff($provided, $expected) !== []) {
                throw new DomainException('Reorder request must contain every active stage exactly once.');
            }

            $update = $this->connection->prepare(
                'UPDATE sales_pipeline_stages
                 SET sort_order = :sort_order, configuration_version = configuration_version + 1
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND id = :stage_id AND status = "ACTIVE"'
            );
            foreach ($items as $item) {
                $update->execute([
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'stage_id' => (string) $item['id'],
                ]);
                if ($update->rowCount() !== 1) throw new DomainException('Stage reorder failed.');
            }
            $this->bumpPipelineVersion($organizationId, $pipelineId, $version);
            $after = $this->requiredPipelineRow($organizationId, $pipelineId);
            $this->revision($organizationId, 'PIPELINE', $pipelineId, (int) $after['configuration_version'], 'UPDATE', $actorId, $before, $after);
        });
    }

    public function replaceTransitions(string $organizationId, string $pipelineId, array $transitions, int $version, string $actorId): void
    {
        $this->transactional(function () use ($organizationId, $pipelineId, $transitions, $version, $actorId): void {
            $pipeline = $this->requiredPipelineRow($organizationId, $pipelineId);
            if ($pipeline['status'] === 'ARCHIVED') throw new DomainException('Archived pipeline cannot be edited.');
            $this->assertVersion($version, (int) $pipeline['configuration_version']);
            $before = $this->transitions($organizationId, $pipelineId);

            $stageRows = $this->all(
                'SELECT id, is_terminal FROM sales_pipeline_stages
                 WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"',
                ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
            );
            $stages = [];
            foreach ($stageRows as $stage) $stages[(string) $stage['id']] = $stage;

            $unique = [];
            foreach ($transitions as $transition) {
                $from = (string) ($transition['from_stage_id'] ?? '');
                $to = (string) ($transition['to_stage_id'] ?? '');
                if (!isset($stages[$from], $stages[$to]) || $from === $to) {
                    throw new DomainException('Transition stages must be distinct active stages of the pipeline.');
                }
                if ((bool) $stages[$from]['is_terminal']) {
                    throw new DomainException('Terminal stages cannot have outgoing transitions.');
                }
                $key = $from . ':' . $to;
                if (isset($unique[$key])) throw new DomainException('Duplicate pipeline transition.');
                $unique[$key] = true;
            }

            $delete = $this->connection->prepare(
                'DELETE FROM sales_pipeline_transitions WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id'
            );
            $delete->execute(['organization_id' => $organizationId, 'pipeline_id' => $pipelineId]);

            $insert = $this->connection->prepare(
                'INSERT INTO sales_pipeline_transitions
                    (id, organization_id, pipeline_id, from_stage_id, to_stage_id, requires_approval, conditions)
                 VALUES (:id, :organization_id, :pipeline_id, :from_stage_id, :to_stage_id, :requires_approval, :conditions)'
            );
            foreach ($transitions as $transition) {
                $insert->execute([
                    'id' => bin2hex(random_bytes(16)),
                    'organization_id' => $organizationId,
                    'pipeline_id' => $pipelineId,
                    'from_stage_id' => (string) $transition['from_stage_id'],
                    'to_stage_id' => (string) $transition['to_stage_id'],
                    'requires_approval' => !empty($transition['requires_approval']) ? 1 : 0,
                    'conditions' => json_encode($transition['conditions'] ?? [], JSON_THROW_ON_ERROR),
                ]);
            }

            $this->bumpPipelineVersion($organizationId, $pipelineId, $version);
            if ($pipeline['status'] === 'ACTIVE') $this->assertPipelineValid($organizationId, $pipelineId);
            $after = $this->transitions($organizationId, $pipelineId);
            $current = $this->requiredPipelineRow($organizationId, $pipelineId);
            $this->revision($organizationId, 'TRANSITION', $pipelineId, (int) $current['configuration_version'], 'UPDATE', $actorId, $before, $after);
        });
    }

    public function lostReasons(string $organizationId, string $pipelineId): array
    {
        return $this->all(
            'SELECT * FROM sales_lost_reasons
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id
             ORDER BY status = "ACTIVE" DESC, sort_order, id',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
    }

    public function createLostReason(string $organizationId, string $pipelineId, array $input, string $actorId): array
    {
        return $this->transactional(function () use ($organizationId, $pipelineId, $input, $actorId): array {
            $pipeline = $this->requiredPipelineRow($organizationId, $pipelineId);
            if ($pipeline['status'] === 'ARCHIVED') throw new DomainException('Archived pipeline cannot be edited.');
            $this->assertVersion((int) ($input['pipeline_version'] ?? 0), (int) $pipeline['configuration_version']);

            $code = strtoupper(trim((string) ($input['code'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,99}$/', $code) || $name === '') {
                throw new DomainException('Valid immutable lost reason code and name are required.');
            }

            $id = bin2hex(random_bytes(16));
            $statement = $this->connection->prepare(
                'INSERT INTO sales_lost_reasons
                    (id, organization_id, pipeline_id, code, name, sort_order, status, configuration_version)
                 VALUES (:id, :organization_id, :pipeline_id, :code, :name, :sort_order, "ACTIVE", 1)'
            );
            $statement->execute([
                'id' => $id,
                'organization_id' => $organizationId,
                'pipeline_id' => $pipelineId,
                'code' => $code,
                'name' => mb_substr($name, 0, 180),
                'sort_order' => (int) ($input['sort_order'] ?? 100),
            ]);
            $this->bumpPipelineVersion($organizationId, $pipelineId, (int) $pipeline['configuration_version']);
            $after = $this->requiredLostReasonRow($organizationId, $id);
            $this->revision($organizationId, 'LOST_REASON', $id, 1, 'CREATE', $actorId, null, $after);
            return $after;
        });
    }

    public function updateLostReason(string $organizationId, string $reasonId, array $input, string $actorId): array
    {
        return $this->transactional(function () use ($organizationId, $reasonId, $input, $actorId): array {
            $before = $this->requiredLostReasonRow($organizationId, $reasonId);
            $pipelineId = (string) $before['pipeline_id'];
            $pipeline = $this->requiredPipelineRow($organizationId, $pipelineId);
            if ($pipeline['status'] === 'ARCHIVED') throw new DomainException('Archived pipeline cannot be edited.');
            $version = (int) ($input['version'] ?? 0);
            $this->assertVersion($version, (int) $before['configuration_version']);

            if (isset($input['code']) && strtoupper(trim((string) $input['code'])) !== (string) $before['code']) {
                throw new DomainException('Lost reason code is immutable.');
            }
            $status = strtoupper(trim((string) ($input['status'] ?? $before['status'])));
            $name = mb_substr(trim((string) ($input['name'] ?? $before['name'])), 0, 180);
            if (!in_array($status, ['ACTIVE', 'ARCHIVED'], true) || $name === '') {
                throw new DomainException('Invalid lost reason configuration.');
            }
            if ($status === 'ARCHIVED' && $pipeline['status'] === 'ACTIVE') {
                $activeReasons = (int) $this->scalar(
                    'SELECT COUNT(*) FROM sales_lost_reasons
                     WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE" AND id <> :reason_id',
                    ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId, 'reason_id' => $reasonId],
                );
                if ($activeReasons === 0) throw new DomainException('An active pipeline requires at least one active lost reason.');
            }

            $statement = $this->connection->prepare(
                'UPDATE sales_lost_reasons
                 SET name = :name, status = :status, sort_order = :sort_order,
                     configuration_version = configuration_version + 1
                 WHERE organization_id = :organization_id AND id = :reason_id AND configuration_version = :version'
            );
            $statement->execute([
                'name' => $name,
                'status' => $status,
                'sort_order' => (int) ($input['sort_order'] ?? $before['sort_order']),
                'organization_id' => $organizationId,
                'reason_id' => $reasonId,
                'version' => $version,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
            $this->bumpPipelineVersion($organizationId, $pipelineId, (int) $pipeline['configuration_version']);

            $after = $this->requiredLostReasonRow($organizationId, $reasonId);
            $this->revision(
                $organizationId,
                'LOST_REASON',
                $reasonId,
                (int) $after['configuration_version'],
                $status === 'ARCHIVED' && $before['status'] !== 'ARCHIVED' ? 'ARCHIVE' : 'UPDATE',
                $actorId,
                $before,
                $after,
            );
            return $after;
        });
    }

    public function validatePipeline(string $organizationId, string $pipelineId): array
    {
        $pipeline = $this->pipelineRow($organizationId, $pipelineId);
        if ($pipeline === null) return ['valid' => false, 'errors' => ['Pipeline not found.'], 'warnings' => []];

        $stages = $this->all(
            'SELECT * FROM sales_pipeline_stages
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"
             ORDER BY sort_order, id',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
        $transitions = $this->transitions($organizationId, $pipelineId);
        $errors = [];
        $warnings = [];
        $stageMap = [];
        foreach ($stages as $stage) $stageMap[(string) $stage['id']] = $stage;

        if ($stages === []) $errors[] = 'Pipeline has no active stages.';
        $initialStageId = (string) ($pipeline['initial_stage_id'] ?? '');
        if ($initialStageId === '' || !isset($stageMap[$initialStageId])) {
            $errors[] = 'An active initial stage is required.';
        } elseif ((bool) $stageMap[$initialStageId]['is_terminal']) {
            $errors[] = 'Initial stage cannot be terminal.';
        }

        $won = array_values(array_filter($stages, static fn (array $stage): bool => (bool) $stage['is_won']));
        $lost = array_values(array_filter($stages, static fn (array $stage): bool => (bool) $stage['is_lost']));
        if (count($won) !== 1) $errors[] = 'Exactly one active WON stage is required.';
        if (count($lost) !== 1) $errors[] = 'Exactly one active LOST stage is required.';

        $graph = [];
        foreach ($transitions as $transition) {
            $from = (string) $transition['from_stage_id'];
            $to = (string) $transition['to_stage_id'];
            if (!isset($stageMap[$from], $stageMap[$to])) {
                $errors[] = 'All transitions must reference active stages.';
                continue;
            }
            if ((bool) $stageMap[$from]['is_terminal']) {
                $errors[] = 'Terminal stage ' . $stageMap[$from]['code'] . ' has an outgoing transition.';
            }
            $graph[$from][] = $to;
        }

        $reachable = [];
        if ($initialStageId !== '' && isset($stageMap[$initialStageId])) {
            $queue = [$initialStageId];
            while ($queue !== []) {
                $current = array_shift($queue);
                if (isset($reachable[$current])) continue;
                $reachable[$current] = true;
                foreach ($graph[$current] ?? [] as $target) $queue[] = $target;
            }
        }
        foreach (array_merge($won, $lost) as $terminalStage) {
            if (!isset($reachable[(string) $terminalStage['id']])) {
                $errors[] = 'Terminal stage ' . $terminalStage['code'] . ' is unreachable from the initial stage.';
            }
        }
        foreach ($stages as $stage) {
            if (!(bool) $stage['is_terminal'] && $initialStageId !== '' && !isset($reachable[(string) $stage['id']])) {
                $warnings[] = 'Stage ' . $stage['code'] . ' is unreachable from the initial stage.';
            }
        }

        $activeLostReasons = (int) $this->scalar(
            'SELECT COUNT(*) FROM sales_lost_reasons
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status = "ACTIVE"',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
        if ($activeLostReasons === 0) $errors[] = 'At least one active lost reason is required.';

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private function assertPipelineValid(string $organizationId, string $pipelineId): void
    {
        $validation = $this->validatePipeline($organizationId, $pipelineId);
        if (!$validation['valid']) {
            throw new DomainException('Pipeline is invalid: ' . implode('; ', $validation['errors']));
        }
    }

    private function transitions(string $organizationId, string $pipelineId): array
    {
        $rows = $this->all(
            'SELECT * FROM sales_pipeline_transitions
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id
             ORDER BY from_stage_id, to_stage_id',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['conditions'] ?? '[]'), true);
            $row['conditions'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }

    private function pipelineRow(string $organizationId, string $pipelineId): ?array
    {
        return $this->one(
            'SELECT * FROM sales_pipelines WHERE organization_id = :organization_id AND id = :pipeline_id LIMIT 1',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
    }

    private function requiredPipelineRow(string $organizationId, string $pipelineId): array
    {
        return $this->pipelineRow($organizationId, $pipelineId) ?? throw new DomainException('Pipeline not found.');
    }

    private function requiredStageRow(string $organizationId, string $stageId): array
    {
        return $this->one(
            'SELECT * FROM sales_pipeline_stages WHERE organization_id = :organization_id AND id = :stage_id LIMIT 1',
            ['organization_id' => $organizationId, 'stage_id' => $stageId],
        ) ?? throw new DomainException('Stage not found.');
    }

    private function requiredLostReasonRow(string $organizationId, string $reasonId): array
    {
        return $this->one(
            'SELECT * FROM sales_lost_reasons WHERE organization_id = :organization_id AND id = :reason_id LIMIT 1',
            ['organization_id' => $organizationId, 'reason_id' => $reasonId],
        ) ?? throw new DomainException('Lost reason not found.');
    }

    private function isActivePipelineStage(string $organizationId, string $pipelineId, string $stageId): bool
    {
        return $this->one(
            'SELECT id FROM sales_pipeline_stages
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND id = :stage_id AND status = "ACTIVE" LIMIT 1',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId, 'stage_id' => $stageId],
        ) !== null;
    }

    private function activeDealCount(string $organizationId, string $pipelineId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM tn_client_cases
             WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id AND status IN ("active", "paused")',
            ['organization_id' => $organizationId, 'pipeline_id' => $pipelineId],
        );
    }

    private function activeDealsAtStage(string $organizationId, string $stageId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM tn_client_cases
             WHERE organization_id = :organization_id AND stage_id = :stage_id AND status IN ("active", "paused")',
            ['organization_id' => $organizationId, 'stage_id' => $stageId],
        );
    }

    private function bumpPipelineVersion(string $organizationId, string $pipelineId, int $version): void
    {
        $statement = $this->connection->prepare(
            'UPDATE sales_pipelines SET configuration_version = configuration_version + 1
             WHERE organization_id = :organization_id AND id = :pipeline_id AND configuration_version = :version'
        );
        $statement->execute(['organization_id' => $organizationId, 'pipeline_id' => $pipelineId, 'version' => $version]);
        if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
    }

    private function assertVersion(int $provided, int $current): void
    {
        if ($provided <= 0 || $provided !== $current) throw new DomainException('CONFIGURATION_CONFLICT');
    }

    private function probability(mixed $value): float
    {
        if (!is_numeric($value)) throw new DomainException('Stage probability must be numeric.');
        $probability = (float) $value;
        if ($probability < 0 || $probability > 100) throw new DomainException('Stage probability must be between 0 and 100.');
        return $probability;
    }

    private function terminalType(array $stage): string
    {
        return (bool) $stage['is_won'] ? 'WON' : ((bool) $stage['is_lost'] ? 'LOST' : 'NONE');
    }

    private function revision(
        string $organizationId,
        string $type,
        string $entityId,
        int $version,
        string $action,
        string $actorId,
        mixed $before,
        mixed $after,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions
                (organization_id, domain_name, configuration_type, entity_id, entity_version, action,
                 actor_type, actor_id, reason, before_payload, after_payload, created_at)
             VALUES (:organization_id, "sales", :configuration_type, :entity_id, :entity_version, :action,
                     "USER", :actor_id, NULL, :before_payload, :after_payload, NOW(6))'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'configuration_type' => $type,
            'entity_id' => $entityId,
            'entity_version' => $version,
            'action' => $action,
            'actor_id' => $actorId,
            'before_payload' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_payload' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
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

    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }
}
