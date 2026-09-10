<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Configuration;

use Kernel\Configuration\Contract\ConfigurationStoreInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Rule;
use PDO;
use Throwable;

final readonly class MysqlConfigurationStore implements ConfigurationStoreInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function provision(
        string $organizationId,
        string $domainName,
        array $rules,
        array $policies,
        string $manifestHash,
        string $actorId,
    ): void {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $ruleStatement = $this->connection->prepare(
                'INSERT INTO cos_rules '
                . '(id, organization_id, domain_name, code, name, trigger_type, conditions, effect, version, priority, status, '
                . 'created_by_type, created_by_id, ownership, configuration_version, system_fingerprint, system_definition, system_update_available) '
                . "VALUES (:id, :organization_id, :domain_name, :code, :name, :trigger, :conditions, :effect, :version, :priority, "
                . "'ACTIVE', 'SYSTEM', :actor_id, 'SYSTEM', 1, :system_fingerprint, :system_definition, 0) "
                . 'ON DUPLICATE KEY UPDATE '
                . 'configuration_version = IF(ownership = \'SYSTEM\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), configuration_version + 1, configuration_version), '
                . 'system_update_available = IF(ownership = \'ADMIN\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), 1, '
                . 'IF(ownership = \'SYSTEM\', 0, system_update_available)), '
                . 'name = IF(ownership = \'SYSTEM\', VALUES(name), name), '
                . 'trigger_type = IF(ownership = \'SYSTEM\', VALUES(trigger_type), trigger_type), '
                . 'conditions = IF(ownership = \'SYSTEM\', VALUES(conditions), conditions), '
                . 'effect = IF(ownership = \'SYSTEM\', VALUES(effect), effect), '
                . 'version = IF(ownership = \'SYSTEM\', VALUES(version), version), '
                . 'priority = IF(ownership = \'SYSTEM\', VALUES(priority), priority), '
                . 'status = IF(ownership = \'SYSTEM\', \'ACTIVE\', status), '
                . 'updated_at = IF(ownership = \'SYSTEM\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), CURRENT_TIMESTAMP(6), updated_at), '
                . 'domain_name = VALUES(domain_name), '
                . 'system_definition = VALUES(system_definition), '
                . 'system_fingerprint = VALUES(system_fingerprint)'
            );

            foreach ($rules as $rule) {
                $before = $this->ruleSnapshot($organizationId, $rule->id);
                $definition = $this->ruleDefinition($rule);
                $fingerprint = $this->fingerprint($definition);

                $ruleStatement->execute([
                    'id' => $rule->id,
                    'organization_id' => $organizationId,
                    'domain_name' => $domainName,
                    'code' => $domainName . '.' . substr(hash('sha256', $rule->name . $rule->trigger), 0, 24),
                    'name' => $rule->name,
                    'trigger' => $rule->trigger,
                    'conditions' => json_encode($rule->conditions, JSON_THROW_ON_ERROR),
                    'effect' => json_encode($rule->effect, JSON_THROW_ON_ERROR),
                    'version' => $rule->version,
                    'priority' => $rule->priority,
                    'actor_id' => $actorId,
                    'system_fingerprint' => $fingerprint,
                    'system_definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                ]);

                $after = $this->ruleSnapshot($organizationId, $rule->id);
                $this->recordProvisionRevision(
                    $organizationId,
                    $domainName,
                    'RULE',
                    $rule->id,
                    $before,
                    $after,
                    $actorId,
                );
            }

            $policyStatement = $this->connection->prepare(
                'INSERT INTO cos_policies '
                . '(id, organization_id, domain_name, code, name, action_type, conditions, decision, priority, version, status, '
                . 'ownership, configuration_version, system_fingerprint, system_definition, system_update_available) '
                . "VALUES (:id, :organization_id, :domain_name, :code, :name, :action_type, :conditions, :decision, :priority, 1, "
                . "'ACTIVE', 'SYSTEM', 1, :system_fingerprint, :system_definition, 0) "
                . 'ON DUPLICATE KEY UPDATE '
                . 'configuration_version = IF(ownership = \'SYSTEM\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), configuration_version + 1, configuration_version), '
                . 'system_update_available = IF(ownership = \'ADMIN\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), 1, '
                . 'IF(ownership = \'SYSTEM\', 0, system_update_available)), '
                . 'name = IF(ownership = \'SYSTEM\', VALUES(name), name), '
                . 'action_type = IF(ownership = \'SYSTEM\', VALUES(action_type), action_type), '
                . 'conditions = IF(ownership = \'SYSTEM\', VALUES(conditions), conditions), '
                . 'decision = IF(ownership = \'SYSTEM\', VALUES(decision), decision), '
                . 'priority = IF(ownership = \'SYSTEM\', VALUES(priority), priority), '
                . 'status = IF(ownership = \'SYSTEM\', \'ACTIVE\', status), '
                . 'updated_at = IF(ownership = \'SYSTEM\' AND NOT (system_fingerprint <=> VALUES(system_fingerprint)), CURRENT_TIMESTAMP(6), updated_at), '
                . 'domain_name = VALUES(domain_name), '
                . 'system_definition = VALUES(system_definition), '
                . 'system_fingerprint = VALUES(system_fingerprint)'
            );

            foreach ($policies as $policy) {
                $before = $this->policySnapshot($organizationId, $policy->id);
                $definition = $this->policyDefinition($policy);
                $fingerprint = $this->fingerprint($definition);

                $policyStatement->execute([
                    'id' => $policy->id,
                    'organization_id' => $organizationId,
                    'domain_name' => $domainName,
                    'code' => $domainName . '.' . substr(hash('sha256', $policy->actionType . $policy->id), 0, 24),
                    'name' => $policy->id,
                    'action_type' => $policy->actionType,
                    'conditions' => json_encode($policy->conditions, JSON_THROW_ON_ERROR),
                    'decision' => $policy->decision->value,
                    'priority' => $policy->priority,
                    'system_fingerprint' => $fingerprint,
                    'system_definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                ]);

                $after = $this->policySnapshot($organizationId, $policy->id);
                $this->recordProvisionRevision(
                    $organizationId,
                    $domainName,
                    'POLICY',
                    $policy->id,
                    $before,
                    $after,
                    $actorId,
                );
            }

            $provision = $this->connection->prepare(
                'INSERT INTO cos_configuration_provisions '
                . '(id, organization_id, domain_name, manifest_hash, rule_count, policy_count, provisioned_by, provisioned_at) '
                . 'VALUES (:id, :organization_id, :domain_name, :manifest_hash, :rule_count, :policy_count, :actor_id, NOW(6))'
            );
            $provision->execute([
                'id' => bin2hex(random_bytes(16)),
                'organization_id' => $organizationId,
                'domain_name' => $domainName,
                'manifest_hash' => $manifestHash,
                'rule_count' => count($rules),
                'policy_count' => count($policies),
                'actor_id' => $actorId,
            ]);

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function ruleDefinition(Rule $rule): array
    {
        return [
            'name' => $rule->name,
            'trigger_type' => $rule->trigger,
            'conditions' => $rule->conditions,
            'effect' => $rule->effect,
            'version' => $rule->version,
            'priority' => $rule->priority,
        ];
    }

    /** @return array<string, mixed> */
    private function policyDefinition(ActionPolicy $policy): array
    {
        return [
            'name' => $policy->id,
            'action_type' => $policy->actionType,
            'conditions' => $policy->conditions,
            'decision' => $policy->decision->value,
            'priority' => $policy->priority,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function fingerprint(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function ruleSnapshot(string $organizationId, string $id): ?array
    {
        return $this->snapshot(
            'SELECT id, domain_name, code, name, trigger_type, conditions, effect, version, priority, status, '
            . 'ownership, configuration_version, system_fingerprint, system_definition, system_update_available '
            . 'FROM cos_rules WHERE organization_id = :organization_id AND id = :id LIMIT 1',
            $organizationId,
            $id,
            ['conditions', 'effect', 'system_definition'],
        );
    }

    /** @return array<string, mixed>|null */
    private function policySnapshot(string $organizationId, string $id): ?array
    {
        return $this->snapshot(
            'SELECT id, domain_name, code, name, action_type, conditions, decision, priority, version, status, '
            . 'ownership, configuration_version, system_fingerprint, system_definition, system_update_available '
            . 'FROM cos_policies WHERE organization_id = :organization_id AND id = :id LIMIT 1',
            $organizationId,
            $id,
            ['conditions', 'system_definition'],
        );
    }

    /**
     * @param list<string> $jsonColumns
     * @return array<string, mixed>|null
     */
    private function snapshot(
        string $sql,
        string $organizationId,
        string $id,
        array $jsonColumns,
    ): ?array {
        $statement = $this->connection->prepare($sql);
        $statement->execute(['organization_id' => $organizationId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        foreach ($jsonColumns as $column) {
            if (!isset($row[$column]) || !is_string($row[$column]) || $row[$column] === '') {
                continue;
            }
            $decoded = json_decode($row[$column], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$column] = $decoded;
            }
        }

        $row['configuration_version'] = (int) ($row['configuration_version'] ?? 0);
        $row['system_update_available'] = (bool) ($row['system_update_available'] ?? false);

        return $row;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function recordProvisionRevision(
        string $organizationId,
        string $domainName,
        string $type,
        string $entityId,
        ?array $before,
        ?array $after,
        string $actorId,
    ): void {
        if ($after === null || $before === $after) {
            return;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions '
            . '(organization_id, domain_name, configuration_type, entity_id, entity_version, action, actor_type, actor_id, '
            . 'before_payload, after_payload, created_at) '
            . "VALUES (:organization_id, :domain_name, :configuration_type, :entity_id, :entity_version, :action, 'SYSTEM', :actor_id, "
            . ':before_payload, :after_payload, NOW(6))'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'domain_name' => $domainName,
            'configuration_type' => $type,
            'entity_id' => $entityId,
            'entity_version' => (int) ($after['configuration_version'] ?? 1),
            'action' => $before === null ? 'CREATE' : 'PROVISION',
            'actor_id' => $actorId,
            'before_payload' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_payload' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }
}
