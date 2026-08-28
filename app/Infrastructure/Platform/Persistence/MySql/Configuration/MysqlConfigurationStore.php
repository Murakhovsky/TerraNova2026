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
        if ($ownsTransaction) $this->connection->beginTransaction();
        try {
            $ruleStatement = $this->connection->prepare(
                'INSERT INTO cos_rules '
                . '(id, organization_id, code, name, trigger_type, conditions, effect, version, priority, status, created_by_type, created_by_id) '
                . "VALUES (:id, :organization_id, :code, :name, :trigger, :conditions, :effect, :version, :priority, 'ACTIVE', 'SYSTEM', :actor_id) "
                . 'ON DUPLICATE KEY UPDATE name = VALUES(name), trigger_type = VALUES(trigger_type), '
                . 'conditions = VALUES(conditions), effect = VALUES(effect), priority = VALUES(priority), '
                . "status = 'ACTIVE', updated_at = CURRENT_TIMESTAMP"
            );
            foreach ($rules as $rule) {
                $ruleStatement->execute([
                    'id' => $rule->id,
                    'organization_id' => $organizationId,
                    'code' => $domainName . '.' . substr(hash('sha256', $rule->name . $rule->trigger), 0, 24),
                    'name' => $rule->name,
                    'trigger' => $rule->trigger,
                    'conditions' => json_encode($rule->conditions, JSON_THROW_ON_ERROR),
                    'effect' => json_encode($rule->effect, JSON_THROW_ON_ERROR),
                    'version' => $rule->version,
                    'priority' => $rule->priority,
                    'actor_id' => $actorId,
                ]);
            }

            $policyStatement = $this->connection->prepare(
                'INSERT INTO cos_policies '
                . '(id, organization_id, code, name, action_type, conditions, decision, priority, version, status) '
                . "VALUES (:id, :organization_id, :code, :name, :action_type, :conditions, :decision, :priority, 1, 'ACTIVE') "
                . 'ON DUPLICATE KEY UPDATE name = VALUES(name), action_type = VALUES(action_type), '
                . 'conditions = VALUES(conditions), decision = VALUES(decision), priority = VALUES(priority), '
                . "status = 'ACTIVE', updated_at = CURRENT_TIMESTAMP"
            );
            foreach ($policies as $policy) {
                $policyStatement->execute([
                    'id' => $policy->id,
                    'organization_id' => $organizationId,
                    'code' => $domainName . '.' . substr(hash('sha256', $policy->actionType . $policy->id), 0, 24),
                    'name' => $policy->id,
                    'action_type' => $policy->actionType,
                    'conditions' => json_encode($policy->conditions, JSON_THROW_ON_ERROR),
                    'decision' => $policy->decision->value,
                    'priority' => $policy->priority,
                ]);
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
            if ($ownsTransaction) $this->connection->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }
}
