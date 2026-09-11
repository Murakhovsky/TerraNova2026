<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Configuration;

use DomainException;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Domains\Sales\Automation\Rule\SalesRuleDefinitionCatalog;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use PDO;
use Throwable;

final readonly class MysqlSalesRuleAdministration implements SalesRuleAdministrationInterface
{
    public function __construct(
        private PDO $connection,
        private SalesRuleDefinitionCatalog $definitions,
        private DomainModuleRegistry $domains,
        private ConditionEvaluator $conditions,
        private PolicyRepositoryInterface $policies,
        private PolicyEngine $policyEngine,
    ) {}

    public function catalog(): array
    {
        return $this->definitions->catalog();
    }

    public function rules(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            "SELECT id, code, name, trigger_type, conditions, effect, version, priority, status, ownership, configuration_version, system_update_available, admin_modified_at, updated_at "
            . "FROM cos_rules WHERE organization_id = :organization_id AND domain_name = 'sales' ORDER BY status = 'ACTIVE' DESC, priority, name"
        );
        $statement->execute(['organization_id' => $organizationId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function rule(string $organizationId, string $ruleId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT id, code, name, trigger_type, conditions, effect, version, priority, status, ownership, configuration_version, system_fingerprint, system_definition, system_update_available, admin_modified_at, updated_at "
            . "FROM cos_rules WHERE organization_id = :organization_id AND domain_name = 'sales' AND id = :id LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'id' => $ruleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createDraft(string $organizationId, array $input, string $actorId): array
    {
        $definition = $this->definitions->normalize($input);
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if ($code === '') $code = 'sales.admin.' . bin2hex(random_bytes(6));
        if (!preg_match('/^sales\.[a-z0-9_.-]{3,140}$/', $code)) {
            throw new DomainException('Rule code must start with sales. and contain only lowercase letters, numbers, dots, underscores or dashes.');
        }

        $id = bin2hex(random_bytes(16));
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "INSERT INTO cos_rules (id, organization_id, domain_name, code, name, trigger_type, conditions, effect, version, priority, status, created_by_type, created_by_id, ownership, configuration_version, admin_modified_at, created_at, updated_at) "
                . "VALUES (:id,:organization_id,'sales',:code,:name,:trigger_type,:conditions,:effect,1,:priority,'DRAFT','USER',:actor_id,'ADMIN',1,NOW(6),NOW(),NOW())"
            );
            $statement->execute([
                'id' => $id,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $definition['name'],
                'trigger_type' => $definition['trigger_type'],
                'conditions' => json_encode($definition['conditions'], JSON_THROW_ON_ERROR),
                'effect' => json_encode($definition['effect'], JSON_THROW_ON_ERROR),
                'priority' => $definition['priority'],
                'actor_id' => $actorId,
            ]);
            $after = $this->rule($organizationId, $id) ?? throw new DomainException('Rule creation failed.');
            $this->revision($organizationId, $id, 1, 'CREATE', $actorId, null, $after);
            $this->connection->commit();
            return $after;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    public function updateDraft(string $organizationId, string $ruleId, array $input, string $actorId): array
    {
        $before = $this->requiredRule($organizationId, $ruleId);
        if (($before['status'] ?? '') === 'ARCHIVED') throw new DomainException('Archived rule cannot be edited.');
        if (($before['status'] ?? '') === 'ACTIVE') throw new DomainException('Disable an active rule before editing it.');

        $expected = (int) ($input['configuration_version'] ?? $input['version'] ?? 0);
        if ($expected < 1 || $expected !== (int) $before['configuration_version']) throw new DomainException('CONFIGURATION_CONFLICT');
        $definition = $this->definitions->normalize($input + [
            'name' => $before['name'],
            'trigger_type' => $before['trigger_type'],
            'conditions' => $before['conditions'],
            'effect' => $before['effect'],
            'priority' => $before['priority'],
        ]);

        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "UPDATE cos_rules SET name=:name, trigger_type=:trigger_type, conditions=:conditions, effect=:effect, priority=:priority, status='DRAFT', ownership='ADMIN', configuration_version=configuration_version+1, admin_modified_at=NOW(6), updated_at=NOW() "
                . "WHERE organization_id=:organization_id AND domain_name='sales' AND id=:id AND configuration_version=:configuration_version"
            );
            $statement->execute([
                'name' => $definition['name'],
                'trigger_type' => $definition['trigger_type'],
                'conditions' => json_encode($definition['conditions'], JSON_THROW_ON_ERROR),
                'effect' => json_encode($definition['effect'], JSON_THROW_ON_ERROR),
                'priority' => $definition['priority'],
                'organization_id' => $organizationId,
                'id' => $ruleId,
                'configuration_version' => $expected,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
            $after = $this->requiredRule($organizationId, $ruleId);
            $this->revision($organizationId, $ruleId, (int) $after['configuration_version'], 'UPDATE', $actorId, $before, $after);
            $this->connection->commit();
            return $after;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    public function activate(string $organizationId, string $ruleId, int $version, string $actorId): array
    {
        $before = $this->requiredRule($organizationId, $ruleId);
        if (($before['status'] ?? '') === 'ARCHIVED') throw new DomainException('Archived rule cannot be activated.');
        $this->assertVersion($before, $version);
        $this->validateRuntimeDefinition($before);
        return $this->transition($organizationId, $ruleId, $version, 'ACTIVE', 'ENABLE', $actorId, $before);
    }

    public function disable(string $organizationId, string $ruleId, int $version, string $actorId): array
    {
        $before = $this->requiredRule($organizationId, $ruleId);
        if (($before['status'] ?? '') === 'ARCHIVED') throw new DomainException('Archived rule cannot be disabled.');
        return $this->transition($organizationId, $ruleId, $version, 'DISABLED', 'DISABLE', $actorId, $before);
    }

    public function archive(string $organizationId, string $ruleId, int $version, string $actorId): array
    {
        return $this->transition($organizationId, $ruleId, $version, 'ARCHIVED', 'ARCHIVE', $actorId, $this->requiredRule($organizationId, $ruleId));
    }

    public function restoreSystem(string $organizationId, string $ruleId, int $version, string $actorId): array
    {
        $before = $this->requiredRule($organizationId, $ruleId);
        if (($before['status'] ?? '') === 'ARCHIVED') throw new DomainException('Archived rule cannot be restored.');
        $this->assertVersion($before, $version);
        $system = $before['system_definition'] ?? null;
        if (!is_array($system)) throw new DomainException('This rule has no system definition to restore.');
        $definition = $this->definitions->normalize([
            'name' => $system['name'] ?? $before['name'],
            'trigger_type' => $system['trigger'] ?? $system['trigger_type'] ?? $before['trigger_type'],
            'conditions' => $system['conditions'] ?? [],
            'effect' => $system['effect'] ?? [],
            'priority' => $system['priority'] ?? $before['priority'],
        ]);

        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "UPDATE cos_rules SET name=:name, trigger_type=:trigger_type, conditions=:conditions, effect=:effect, priority=:priority, status='DRAFT', ownership='ADMIN', configuration_version=configuration_version+1, system_update_available=0, admin_modified_at=NOW(6), updated_at=NOW() "
                . "WHERE organization_id=:organization_id AND domain_name='sales' AND id=:id AND configuration_version=:configuration_version"
            );
            $statement->execute([
                'name' => $definition['name'],
                'trigger_type' => $definition['trigger_type'],
                'conditions' => json_encode($definition['conditions'], JSON_THROW_ON_ERROR),
                'effect' => json_encode($definition['effect'], JSON_THROW_ON_ERROR),
                'priority' => $definition['priority'],
                'organization_id' => $organizationId,
                'id' => $ruleId,
                'configuration_version' => $version,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
            $after = $this->requiredRule($organizationId, $ruleId);
            $this->revision($organizationId, $ruleId, (int) $after['configuration_version'], 'ROLLBACK', $actorId, $before, $after);
            $this->connection->commit();
            return $after;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    public function dryRun(string $organizationId, string $ruleId): array
    {
        $rule = $this->requiredRule($organizationId, $ruleId);
        $this->validateRuntimeDefinition($rule);
        $triggerKey = $this->definitions->keyForCanonical((string) $rule['trigger_type']);
        $trigger = null;
        foreach ((array) ($this->definitions->catalog()['triggers'] ?? []) as $candidate) {
            if (($candidate['key'] ?? null) === $triggerKey) {
                $trigger = $candidate;
                break;
            }
        }
        $subjectType = (string) ($trigger['subject'] ?? 'deal');
        $rows = $subjectType === 'lead' ? $this->leadContexts($organizationId) : $this->dealContexts($organizationId);
        $actions = $this->actionsFromEffect((array) ($rule['effect'] ?? []));
        $matched = 0;
        $wouldAuto = 0;
        $wouldApproval = 0;
        $wouldDenied = 0;
        $wouldHumanOnly = 0;

        foreach ($rows as $context) {
            if (!$this->conditions->matches((array) $rule['conditions'], $context)) continue;
            $matched++;
            foreach ($actions as $action) {
                $actionType = (string) ($action['type'] ?? '');
                if ($actionType === '') continue;
                if (str_starts_with($actionType, 'agent.run.')) {
                    $wouldAuto++;
                    continue;
                }
                $evaluation = $this->policyEngine->evaluate(
                    $actionType,
                    ['action' => [
                        'type' => $actionType,
                        'parameters' => (array) ($action['parameters'] ?? []),
                        'risk_level' => (string) ($action['risk_level'] ?? 'LOW'),
                        'source_type' => 'RULE',
                        'target_type' => $subjectType,
                    ]],
                    $this->policies->activeFor($organizationId, $actionType),
                );
                match ($evaluation->decision->value) {
                    'AUTO' => $wouldAuto++,
                    'APPROVAL_REQUIRED' => $wouldApproval++,
                    'HUMAN_ONLY' => $wouldHumanOnly++,
                    default => $wouldDenied++,
                };
            }
        }

        return [
            'sample_size' => count($rows),
            'matched_subjects' => $matched,
            'matched_deals' => $subjectType === 'deal' ? $matched : 0,
            'matched_leads' => $subjectType === 'lead' ? $matched : 0,
            'would_trigger' => $matched,
            'would_auto' => $wouldAuto,
            'would_require_approval' => $wouldApproval,
            'would_be_denied' => $wouldDenied,
            'would_be_human_only' => $wouldHumanOnly,
            'mutations' => 0,
            'note' => 'Read-only preview. No actions, approvals, agent runs or business mutations are created.',
        ];
    }

    public function revisions(string $organizationId, string $ruleId, int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $statement = $this->connection->prepare(
            "SELECT id, entity_version, action, actor_type, actor_id, reason, before_payload, after_payload, created_at FROM cos_configuration_revisions "
            . "WHERE organization_id=:organization_id AND domain_name='sales' AND configuration_type='RULE' AND entity_id=:entity_id ORDER BY id DESC LIMIT {$limit}"
        );
        $statement->execute(['organization_id' => $organizationId, 'entity_id' => $ruleId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['before_payload', 'after_payload'] as $column) {
                if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                    $row[$column] = json_decode((string) $row[$column], true);
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function transition(
        string $organizationId,
        string $ruleId,
        int $version,
        string $status,
        string $action,
        string $actorId,
        array $before,
    ): array {
        $this->assertVersion($before, $version);
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "UPDATE cos_rules SET status=:status, ownership='ADMIN', configuration_version=configuration_version+1, admin_modified_at=NOW(6), updated_at=NOW() "
                . "WHERE organization_id=:organization_id AND domain_name='sales' AND id=:id AND configuration_version=:configuration_version"
            );
            $statement->execute([
                'status' => $status,
                'organization_id' => $organizationId,
                'id' => $ruleId,
                'configuration_version' => $version,
            ]);
            if ($statement->rowCount() !== 1) throw new DomainException('CONFIGURATION_CONFLICT');
            $after = $this->requiredRule($organizationId, $ruleId);
            $this->revision($organizationId, $ruleId, (int) $after['configuration_version'], $action, $actorId, $before, $after);
            $this->connection->commit();
            return $after;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    private function validateRuntimeDefinition(array $row): void
    {
        $normalized = $this->definitions->normalize([
            'name' => $row['name'],
            'trigger_type' => $row['trigger_type'],
            'conditions' => $row['conditions'],
            'effect' => $row['effect'],
            'priority' => $row['priority'],
        ]);
        if (!$this->domains->ownsEvent('sales', $normalized['trigger_type'])) {
            throw new DomainException('Trigger is not owned by Sales.');
        }
        foreach ($normalized['actions'] as $action) {
            $type = (string) $action['type'];
            if (str_starts_with($type, 'agent.run.')) {
                $agent = substr($type, strlen('agent.run.'));
                if (!$this->domains->hasAgent($agent) || $this->domains->ownerOfAgent($agent) !== 'sales') {
                    throw new DomainException('Agent is not owned by Sales.');
                }
            } elseif (!$this->domains->ownsAction('sales', $type)) {
                throw new DomainException('Action is not owned by Sales: ' . $type);
            }
        }
    }

    private function requiredRule(string $organizationId, string $ruleId): array
    {
        return $this->rule($organizationId, $ruleId) ?? throw new DomainException('Sales rule not found.');
    }

    private function assertVersion(array $row, int $version): void
    {
        if ($version < 1 || (int) ($row['configuration_version'] ?? 0) !== $version) {
            throw new DomainException('CONFIGURATION_CONFLICT');
        }
    }

    private function hydrate(array $row): array
    {
        foreach (['conditions', 'effect', 'system_definition'] as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $row[$column] = json_decode($row[$column], true);
            }
        }
        $row['version'] = (int) ($row['version'] ?? 1);
        $row['priority'] = (int) ($row['priority'] ?? 100);
        $row['configuration_version'] = (int) ($row['configuration_version'] ?? 1);
        $row['system_update_available'] = (bool) ($row['system_update_available'] ?? false);
        try {
            $row['trigger_key'] = $this->definitions->keyForCanonical((string) $row['trigger_type']);
        } catch (Throwable) {
            $row['trigger_key'] = null;
        }
        $row['actions'] = $this->actionsFromEffect(is_array($row['effect'] ?? null) ? $row['effect'] : []);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function actionsFromEffect(array $effect): array
    {
        if (is_array($effect['actions'] ?? null)) {
            return array_values(array_filter($effect['actions'], 'is_array'));
        }
        $type = trim((string) ($effect['action_type'] ?? ''));
        if ($type === '') return [];
        return [[
            'type' => $type,
            'target_type' => $effect['target_type'] ?? null,
            'target_id' => $effect['target_id'] ?? null,
            'parameters' => is_array($effect['parameters'] ?? null) ? $effect['parameters'] : [],
            'execution_mode' => (string) ($effect['execution_mode'] ?? 'MANUAL'),
            'risk_level' => (string) ($effect['risk_level'] ?? 'LOW'),
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function dealContexts(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            "SELECT c.id,c.status,c.pipeline_id,c.stage_id,s.code AS stage_code,c.priority,c.assigned_user_id,c.source,c.last_activity_at,c.deal_value,c.next_contact_at,c.lost_reason,c.updated_at "
            . "FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id "
            . "WHERE c.organization_id=:organization_id ORDER BY c.updated_at DESC LIMIT 500"
        );
        $statement->execute(['organization_id' => $organizationId]);
        $now = time();
        return array_map(static function (array $row) use ($now): array {
            $last = strtotime((string) ($row['last_activity_at'] ?? ''));
            $days = $last === false ? 99999.0 : max(0.0, ($now - $last) / 86400);
            $updated = strtotime((string) ($row['updated_at'] ?? ''));
            $value = (float) ($row['deal_value'] ?? 0);
            $status = strtolower((string) ($row['status'] ?? ''));
            return ['deal' => [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'pipeline_id' => $row['pipeline_id'],
                'stage_id' => $row['stage_id'],
                'stage_code' => $row['stage_code'],
                'priority' => $row['priority'],
                'risk' => null,
                'days_without_activity' => $days,
                'owner_id' => $row['assigned_user_id'],
                'source' => $row['source'],
                'value' => $value,
                'next_contact_at' => $row['next_contact_at'],
                'stuck_in_stage' => $updated !== false && $updated <= $now - 604800,
                'no_activity_48h' => $days >= 2,
                'high_value' => $value >= 100000,
                'lost_reason_missing' => $status === 'lost' && trim((string) ($row['lost_reason'] ?? '')) === '',
            ]];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function leadContexts(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            "SELECT id,status,source,assigned_user_id FROM tn_leads WHERE organization_id=:organization_id ORDER BY updated_at DESC LIMIT 500"
        );
        $statement->execute(['organization_id' => $organizationId]);
        return array_map(static fn (array $row): array => ['lead' => [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'source' => $row['source'],
            'owner_id' => $row['assigned_user_id'],
        ]], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function revision(
        string $organizationId,
        string $ruleId,
        int $version,
        string $action,
        string $actorId,
        ?array $before,
        array $after,
    ): void {
        $statement = $this->connection->prepare(
            "INSERT INTO cos_configuration_revisions (organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,before_payload,after_payload,created_at) "
            . "VALUES (:organization_id,'sales','RULE',:entity_id,:entity_version,:action,'USER',:actor_id,:before_payload,:after_payload,NOW(6))"
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'entity_id' => $ruleId,
            'entity_version' => $version,
            'action' => $action,
            'actor_id' => $actorId,
            'before_payload' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_payload' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }
}
