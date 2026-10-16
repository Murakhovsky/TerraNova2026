<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

/**
 * Describes a public executable contract. This declaration is not permission to execute it.
 * The owning module.php remains the source of truth for the capability identity.
 */
final readonly class CapabilityContract
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $tests
     * @param array<string,mixed> $retryPolicy
     * @param array<string,mixed> $timeoutPolicy
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $ownerDomain,
        public string $kind,
        public string $inputSchema,
        public string $outputSchema,
        public string $executionBinding,
        public bool $async = false,
        public string $sideEffectLevel = 'none',
        public ?string $permission = null,
        public string $approvalPolicy = 'none',
        public string $idempotency = 'none',
        public array $timeoutPolicy = [],
        public array $retryPolicy = [],
        public string $failureContract = '',
        public array $dependencies = [],
        public string $lifecycle = 'experimental',
        public array $tests = [],
        public string $documentation = '',
        public string $titleKey = '',
        public string $descriptionKey = '',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $this->id)) {
            throw new InvalidArgumentException('Invalid namespaced capability id: ' . $this->id);
        }
        VersionConstraint::assertVersion($this->version, 'capability contract version');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->ownerDomain)) {
            throw new InvalidArgumentException('Invalid capability owner: ' . $this->ownerDomain);
        }
        if (!in_array($this->kind, ['query', 'command', 'job', 'workflow', 'agent_tool'], true)) {
            throw new InvalidArgumentException('Invalid capability kind: ' . $this->kind);
        }
        foreach (['input schema' => $this->inputSchema, 'output schema' => $this->outputSchema, 'execution binding' => $this->executionBinding] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Missing capability ' . $label . ': ' . $this->id);
            }
        }
        if (!in_array($this->sideEffectLevel, ['none', 'internal', 'external', 'financial', 'irreversible'], true)) {
            throw new InvalidArgumentException('Invalid capability side effect: ' . $this->id);
        }
        if (!in_array($this->approvalPolicy, ['none', 'conditional', 'required'], true)
            || !in_array($this->idempotency, ['none', 'required'], true)
            || !in_array($this->lifecycle, ['experimental', 'stable', 'deprecated', 'retired'], true)) {
            throw new InvalidArgumentException('Invalid capability governance policy: ' . $this->id);
        }
        if ($this->sideEffectLevel !== 'none' || $this->kind !== 'query') {
            if (!is_string($this->permission) || trim($this->permission) === '') {
                throw new InvalidArgumentException('Mutation/job capability requires explicit permission: ' . $this->id);
            }
        }
        if ($this->sideEffectLevel !== 'none') {
            if ($this->idempotency !== 'required'
                || !isset($this->timeoutPolicy['seconds'])
                || !is_int($this->timeoutPolicy['seconds'])
                || $this->timeoutPolicy['seconds'] < 1
                || !isset($this->retryPolicy['max_attempts'])
                || !is_int($this->retryPolicy['max_attempts'])
                || $this->retryPolicy['max_attempts'] < 1
                || trim($this->failureContract) === '') {
                throw new InvalidArgumentException('Side-effectful capability needs idempotency, timeout, retry and failure contract: ' . $this->id);
            }
        }
        if (in_array($this->sideEffectLevel, ['external', 'financial', 'irreversible'], true)
            && $this->approvalPolicy === 'none') {
            throw new InvalidArgumentException('External/financial capability requires approval policy: ' . $this->id);
        }
        if (count($this->dependencies) !== count(array_unique($this->dependencies))
            || count($this->tests) !== count(array_unique($this->tests))) {
            throw new InvalidArgumentException('Duplicate capability dependencies/tests: ' . $this->id);
        }
        foreach ([...$this->dependencies, ...$this->tests] as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('Invalid capability dependency/test: ' . $this->id);
            }
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            ownerDomain: (string) ($data['owner_domain'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            inputSchema: (string) ($data['input_schema'] ?? ''),
            outputSchema: (string) ($data['output_schema'] ?? ''),
            executionBinding: (string) ($data['execution_binding'] ?? ''),
            async: (bool) ($data['async'] ?? false),
            sideEffectLevel: (string) ($data['side_effect_level'] ?? 'none'),
            permission: isset($data['permission']) ? (string) $data['permission'] : null,
            approvalPolicy: (string) ($data['approval_policy'] ?? 'none'),
            idempotency: (string) ($data['idempotency'] ?? 'none'),
            timeoutPolicy: self::object($data['timeout_policy'] ?? []),
            retryPolicy: self::object($data['retry_policy'] ?? []),
            failureContract: (string) ($data['failure_contract'] ?? ''),
            dependencies: self::strings($data['dependencies'] ?? []),
            lifecycle: (string) ($data['lifecycle'] ?? 'experimental'),
            tests: self::strings($data['tests'] ?? []),
            documentation: (string) ($data['documentation'] ?? ''),
            titleKey: (string) ($data['title_key'] ?? ''),
            descriptionKey: (string) ($data['description_key'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'version' => $this->version,
            'owner_domain' => $this->ownerDomain, 'kind' => $this->kind,
            'input_schema' => $this->inputSchema, 'output_schema' => $this->outputSchema,
            'execution_binding' => $this->executionBinding, 'async' => $this->async,
            'side_effect_level' => $this->sideEffectLevel, 'permission' => $this->permission,
            'approval_policy' => $this->approvalPolicy, 'idempotency' => $this->idempotency,
            'timeout_policy' => $this->timeoutPolicy, 'retry_policy' => $this->retryPolicy,
            'failure_contract' => $this->failureContract, 'dependencies' => $this->dependencies,
            'lifecycle' => $this->lifecycle, 'tests' => $this->tests,
            'documentation' => $this->documentation, 'title_key' => $this->titleKey,
            'description_key' => $this->descriptionKey,
        ];
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Capability policy must be an object.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Capability dependencies/tests must be lists.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Capability dependency/test must be a string.');
            }
        }
        return $value;
    }
}
