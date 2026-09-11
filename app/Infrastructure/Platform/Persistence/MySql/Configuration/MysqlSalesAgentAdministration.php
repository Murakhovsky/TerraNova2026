<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Configuration;

use DomainException;
use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Domains\Sales\Automation\Agent\SalesIntelligenceAgent;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlSalesAgentAdministration implements SalesAgentAdministrationInterface
{
    private const DOMAIN = 'sales';
    private const SYSTEM_ACTOR = 'sales.v0.7.4';
    private const CODE_OWNED_FIELDS = [
        'system_prompt', 'schema_version', 'prompt_version', 'agent_version', 'hard_allowed_actions',
        'max_actions_per_run', 'evidence_schema', 'result_validator', 'default_execution_mode', 'default_risk_level',
    ];

    public function __construct(
        private PDO $connection,
        private MysqlAgentConfigurationProvider $configurations,
        private AgentRuntime $runtime,
    ) {}

    public function catalog(): array
    {
        return [
            'profiles' => ['balanced', 'conservative', 'growth'],
            'context_sources' => SalesIntelligenceAgent::CONTEXT_SOURCES,
            'hard_allowed_actions' => SalesIntelligenceAgent::HARD_ALLOWED_ACTIONS,
            'code_owned' => SalesIntelligenceAgent::systemContract(),
            'admin_owned' => [
                'enabled', 'profile', 'model', 'business_instructions', 'context_sources', 'allowed_actions', 'confidence_threshold',
            ],
        ];
    }

    public function agents(string $organizationId): array
    {
        $this->provision($organizationId);
        $statement = $this->connection->prepare(
            'SELECT agent_name,enabled,profile,model,confidence_threshold,ownership,configuration_version,system_update_available,updated_at '
            . 'FROM cos_agent_configurations WHERE organization_id=:organization_id AND domain_name=:domain_name ORDER BY agent_name'
        );
        $statement->execute(['organization_id' => $organizationId, 'domain_name' => self::DOMAIN]);
        return array_map([$this, 'normalizeRow'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function agent(string $organizationId, string $agentName): ?array
    {
        $this->assertAgent($agentName);
        $this->provision($organizationId);
        $row = $this->row($organizationId, $agentName);
        if ($row === null) return null;
        $normalized = $this->normalizeRow($row);
        $normalized['code_owned'] = SalesIntelligenceAgent::systemContract();
        $normalized['catalog'] = $this->catalog();
        return $normalized;
    }

    public function update(string $organizationId, string $agentName, array $input, int $expectedVersion, string $actorId): array
    {
        $this->assertAgent($agentName);
        $this->assertAdminInput($input);
        if ($expectedVersion < 1) throw new DomainException('configuration_version is required.');
        if (trim($actorId) === '') throw new DomainException('actor_id is required.');
        $this->provision($organizationId);

        $before = $this->row($organizationId, $agentName);
        if ($before === null) throw new RuntimeException('Sales agent configuration was not provisioned.');
        if ((int) $before['configuration_version'] !== $expectedVersion) throw new RuntimeException('CONFIGURATION_CONFLICT');

        $configuration = $this->validatedConfiguration($input, $before);
        $nextVersion = $expectedVersion + 1;
        $now = $this->now();
        $statement = $this->connection->prepare(
            'UPDATE cos_agent_configurations SET enabled=:enabled,profile=:profile,model=:model,business_instructions=:business_instructions,'
            . 'context_sources=:context_sources,allowed_actions=:allowed_actions,confidence_threshold=:confidence_threshold,ownership="ADMIN",'
            . 'configuration_version=:next_version,system_update_available=0,admin_modified_at=:now,updated_at=:now '
            . 'WHERE organization_id=:organization_id AND domain_name=:domain_name AND agent_name=:agent_name AND configuration_version=:expected_version'
        );

        $this->connection->beginTransaction();
        try {
            $statement->execute([
                'enabled' => $configuration['enabled'] ? 1 : 0,
                'profile' => $configuration['profile'],
                'model' => $configuration['model'],
                'business_instructions' => $configuration['business_instructions'],
                'context_sources' => json_encode($configuration['context_sources'], JSON_THROW_ON_ERROR),
                'allowed_actions' => json_encode($configuration['allowed_actions'], JSON_THROW_ON_ERROR),
                'confidence_threshold' => $configuration['confidence_threshold'],
                'next_version' => $nextVersion,
                'now' => $now,
                'organization_id' => $organizationId,
                'domain_name' => self::DOMAIN,
                'agent_name' => $agentName,
                'expected_version' => $expectedVersion,
            ]);
            if ($statement->rowCount() !== 1) throw new RuntimeException('CONFIGURATION_CONFLICT');
            $after = $this->row($organizationId, $agentName);
            if ($after === null) throw new RuntimeException('Sales agent configuration disappeared during update.');
            $action = ((int) $before['enabled'] !== (int) $after['enabled'])
                ? ((int) $after['enabled'] === 1 ? 'ENABLE' : 'DISABLE')
                : 'UPDATE';
            $this->revision($organizationId, $agentName, $nextVersion, $action, 'USER', $actorId, $before, $after);
            $this->connection->commit();
            return $this->agent($organizationId, $agentName) ?? throw new RuntimeException('Sales agent configuration not found after update.');
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    public function test(string $organizationId, string $agentName, array $input, string $actorId): array
    {
        $this->assertAgent($agentName);
        $this->provision($organizationId);
        $subjectType = trim((string) ($input['subject_type'] ?? 'deal'));
        $subjectId = trim((string) ($input['subject_id'] ?? ''));
        $question = trim((string) ($input['question'] ?? 'Analyze this Sales case and return the current assessment.'));
        if (!in_array($subjectType, ['deal', 'client_case'], true)) throw new DomainException('subject_type must be deal or client_case.');
        if ($subjectId === '' || strlen($subjectId) > 100) throw new DomainException('subject_id is required for read-only test.');
        if ($question === '' || mb_strlen($question) > 2000) throw new DomainException('question must contain 1-2000 characters.');

        $effective = $this->configurations->effective($organizationId, SalesIntelligenceAgent::definition());
        $testDefinition = new AgentDefinition(
            $effective->name,
            $effective->version,
            $effective->systemPrompt . "\n\nREAD-ONLY ADMIN TEST: proposed_actions MUST be an empty array. Do not request or imply any business mutation.",
            $effective->promptVersion,
            $effective->schemaVersion,
            [],
            $effective->defaultExecutionMode,
            $effective->defaultRiskLevel,
            $effective->evidenceSchemas,
            $effective->resultValidatorClass,
            $effective->domainName,
            true,
            $effective->profile,
            $effective->model,
            $effective->contextSources,
            $effective->confidenceThreshold,
            0,
            false,
        );
        $correlationId = 'agent-admin-test-' . bin2hex(random_bytes(8));
        $execution = $this->runtime->run($testDefinition, new AgentInvocation(
            $organizationId,
            $subjectType,
            $subjectId,
            $question,
            $correlationId,
            ['read_only' => true, 'requested_by' => $actorId],
            $agentName,
        ));

        return [
            'run_id' => $execution->runId,
            'correlation_id' => $correlationId,
            'read_only' => true,
            'action_execution' => 'DISABLED',
            'decision' => $execution->result->decision,
            'reason' => $execution->result->reason,
            'confidence' => $execution->result->confidence,
            'evidence' => $execution->result->evidence,
            'proposed_actions' => [],
            'configuration_version' => (int) (($this->row($organizationId, $agentName)['configuration_version'] ?? 1)),
        ];
    }

    public function revisions(string $organizationId, string $agentName, int $limit = 100): array
    {
        $this->assertAgent($agentName);
        $limit = max(1, min(200, $limit));
        $statement = $this->connection->prepare(
            'SELECT entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at '
            . 'FROM cos_configuration_revisions WHERE organization_id=:organization_id AND domain_name=:domain_name '
            . 'AND configuration_type="AGENT" AND entity_id=:entity_id ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute(['organization_id' => $organizationId, 'domain_name' => self::DOMAIN, 'entity_id' => $agentName]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['before_payload', 'after_payload'] as $field) {
                if (is_string($row[$field] ?? null)) $row[$field] = json_decode((string) $row[$field], true) ?? [];
            }
            $row['entity_version'] = (int) $row['entity_version'];
        }
        return $rows;
    }

    private function provision(string $organizationId): void
    {
        $definition = SalesIntelligenceAgent::definition();
        $defaults = SalesIntelligenceAgent::defaultRuntimeConfiguration();
        $systemDefinition = ['contract' => SalesIntelligenceAgent::systemContract(), 'defaults' => $defaults];
        $fingerprint = hash('sha256', json_encode($systemDefinition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $existing = $this->row($organizationId, $definition->name);
        $now = $this->now();

        if ($existing === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO cos_agent_configurations (organization_id,domain_name,agent_name,enabled,profile,model,business_instructions,context_sources,allowed_actions,'
                . 'confidence_threshold,ownership,configuration_version,system_fingerprint,system_definition,system_update_available,admin_modified_at,created_at,updated_at) '
                . 'VALUES (:organization_id,:domain_name,:agent_name,:enabled,:profile,:model,:business_instructions,:context_sources,:allowed_actions,:confidence_threshold,"SYSTEM",1,'
                . ':system_fingerprint,:system_definition,0,NULL,:now,:now)'
            );
            $this->connection->beginTransaction();
            try {
                $statement->execute([
                    'organization_id' => $organizationId,
                    'domain_name' => self::DOMAIN,
                    'agent_name' => $definition->name,
                    'enabled' => $defaults['enabled'] ? 1 : 0,
                    'profile' => $defaults['profile'],
                    'model' => $defaults['model'],
                    'business_instructions' => $defaults['business_instructions'],
                    'context_sources' => json_encode($defaults['context_sources'], JSON_THROW_ON_ERROR),
                    'allowed_actions' => json_encode($defaults['allowed_actions'], JSON_THROW_ON_ERROR),
                    'confidence_threshold' => $defaults['confidence_threshold'],
                    'system_fingerprint' => $fingerprint,
                    'system_definition' => json_encode($systemDefinition, JSON_THROW_ON_ERROR),
                    'now' => $now,
                ]);
                $created = $this->row($organizationId, $definition->name) ?? [];
                $this->revision($organizationId, $definition->name, 1, 'CREATE', 'SYSTEM', self::SYSTEM_ACTOR, null, $created);
                $this->connection->commit();
            } catch (Throwable $exception) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                throw $exception;
            }
            return;
        }

        if (hash_equals((string) $existing['system_fingerprint'], $fingerprint)) return;
        if ((string) $existing['ownership'] === 'ADMIN') {
            $statement = $this->connection->prepare(
                'UPDATE cos_agent_configurations SET system_fingerprint=:fingerprint,system_definition=:definition,system_update_available=1,updated_at=:now '
                . 'WHERE organization_id=:organization_id AND domain_name=:domain_name AND agent_name=:agent_name'
            );
            $this->connection->beginTransaction();
            try {
                $statement->execute([
                    'fingerprint' => $fingerprint,
                    'definition' => json_encode($systemDefinition, JSON_THROW_ON_ERROR),
                    'now' => $now,
                    'organization_id' => $organizationId,
                    'domain_name' => self::DOMAIN,
                    'agent_name' => $definition->name,
                ]);
                $after = $this->row($organizationId, $definition->name) ?? [];
                $this->revision(
                    $organizationId,
                    $definition->name,
                    (int) $existing['configuration_version'],
                    'PROVISION',
                    'SYSTEM',
                    self::SYSTEM_ACTOR,
                    $existing,
                    $after,
                );
                $this->connection->commit();
            } catch (Throwable $exception) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                throw $exception;
            }
            return;
        }

        $nextVersion = (int) $existing['configuration_version'] + 1;
        $statement = $this->connection->prepare(
            'UPDATE cos_agent_configurations SET enabled=:enabled,profile=:profile,model=:model,business_instructions=:business_instructions,context_sources=:context_sources,'
            . 'allowed_actions=:allowed_actions,confidence_threshold=:confidence_threshold,configuration_version=:next_version,system_fingerprint=:fingerprint,'
            . 'system_definition=:definition,system_update_available=0,updated_at=:now WHERE organization_id=:organization_id AND domain_name=:domain_name AND agent_name=:agent_name'
        );
        $this->connection->beginTransaction();
        try {
            $statement->execute([
                'enabled' => $defaults['enabled'] ? 1 : 0,
                'profile' => $defaults['profile'],
                'model' => $defaults['model'],
                'business_instructions' => $defaults['business_instructions'],
                'context_sources' => json_encode($defaults['context_sources'], JSON_THROW_ON_ERROR),
                'allowed_actions' => json_encode($defaults['allowed_actions'], JSON_THROW_ON_ERROR),
                'confidence_threshold' => $defaults['confidence_threshold'],
                'next_version' => $nextVersion,
                'fingerprint' => $fingerprint,
                'definition' => json_encode($systemDefinition, JSON_THROW_ON_ERROR),
                'now' => $now,
                'organization_id' => $organizationId,
                'domain_name' => self::DOMAIN,
                'agent_name' => $definition->name,
            ]);
            $after = $this->row($organizationId, $definition->name) ?? [];
            $this->revision($organizationId, $definition->name, $nextVersion, 'PROVISION', 'SYSTEM', self::SYSTEM_ACTOR, $existing, $after);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    private function validatedConfiguration(array $input, array $existing): array
    {
        $profile = trim((string) ($input['profile'] ?? $existing['profile'] ?? SalesIntelligenceAgent::DEFAULT_PROFILE));
        if (!in_array($profile, $this->catalog()['profiles'], true)) throw new DomainException('Unsupported Sales Intelligence profile.');
        $model = trim((string) ($input['model'] ?? $existing['model'] ?? ''));
        if ($model !== '' && (!preg_match('/^[A-Za-z0-9._:\/-]{1,160}$/', $model))) throw new DomainException('Invalid model identifier.');
        $instructions = trim((string) ($input['business_instructions'] ?? $existing['business_instructions'] ?? ''));
        if (mb_strlen($instructions) > 10000) throw new DomainException('Business instructions are limited to 10000 characters.');
        $context = $this->listInput($input, 'context_sources', $existing['context_sources'] ?? []);
        if (array_diff($context, SalesIntelligenceAgent::CONTEXT_SOURCES) !== []) throw new DomainException('Unsupported Sales Intelligence context source.');
        $actions = $this->listInput($input, 'allowed_actions', $existing['allowed_actions'] ?? []);
        if (array_diff($actions, SalesIntelligenceAgent::HARD_ALLOWED_ACTIONS) !== []) throw new DomainException('Allowed actions must be a subset of the code-owned action allowlist.');
        $threshold = $input['confidence_threshold'] ?? $existing['confidence_threshold'] ?? SalesIntelligenceAgent::DEFAULT_CONFIDENCE_THRESHOLD;
        if (!is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 1) throw new DomainException('confidence_threshold must be between 0 and 1.');
        return [
            'enabled' => $this->boolInput($input['enabled'] ?? $existing['enabled'] ?? true),
            'profile' => $profile,
            'model' => $model !== '' ? $model : null,
            'business_instructions' => $instructions,
            'context_sources' => $context,
            'allowed_actions' => $actions,
            'confidence_threshold' => (float) $threshold,
        ];
    }

    private function assertAdminInput(array $input): void
    {
        foreach (self::CODE_OWNED_FIELDS as $field) {
            if (array_key_exists($field, $input)) throw new DomainException(sprintf('%s is code-owned and cannot be changed from Sales Admin.', $field));
        }
    }

    private function assertAgent(string $agentName): void
    {
        if ($agentName !== SalesIntelligenceAgent::NAME) throw new DomainException('Unknown Sales agent.');
    }

    private function row(string $organizationId, string $agentName): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cos_agent_configurations WHERE organization_id=:organization_id AND domain_name=:domain_name AND agent_name=:agent_name LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'domain_name' => self::DOMAIN, 'agent_name' => $agentName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function normalizeRow(array $row): array
    {
        foreach (['context_sources', 'allowed_actions', 'system_definition'] as $field) {
            if (is_string($row[$field] ?? null)) $row[$field] = json_decode((string) $row[$field], true) ?? [];
        }
        $row['enabled'] = (bool) ($row['enabled'] ?? false);
        $row['configuration_version'] = (int) ($row['configuration_version'] ?? 1);
        $row['system_update_available'] = (bool) ($row['system_update_available'] ?? false);
        $row['confidence_threshold'] = (float) ($row['confidence_threshold'] ?? 0.0);
        return $row;
    }

    /** @return list<string> */
    private function listInput(array $input, string $key, mixed $fallback): array
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $fallback;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
            elseif ($value === '') $value = [];
            else $value = preg_split('/\s*,\s*/', $value) ?: [];
        }
        if (!is_array($value)) throw new DomainException($key . ' must be an array.');
        return array_values(array_unique(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== '')));
    }

    private function boolInput(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function revision(string $organizationId, string $agentName, int $version, string $action, string $actorType, string $actorId, ?array $before, array $after): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions (organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at) '
            . 'VALUES (:organization_id,:domain_name,"AGENT",:entity_id,:entity_version,:action,:actor_type,:actor_id,NULL,:before_payload,:after_payload,:created_at)'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'domain_name' => self::DOMAIN,
            'entity_id' => $agentName,
            'entity_version' => $version,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'before_payload' => $before === null ? null : json_encode($this->normalizeRow($before), JSON_THROW_ON_ERROR),
            'after_payload' => json_encode($this->normalizeRow($after), JSON_THROW_ON_ERROR),
            'created_at' => $this->now(),
        ]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }
}
