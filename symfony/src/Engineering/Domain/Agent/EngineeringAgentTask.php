<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

use App\Engineering\Domain\Workflow\EngineeringId;
use InvalidArgumentException;

final readonly class EngineeringAgentTask
{
    // Persisted in cos_engineering_agent_runs.idempotency_key VARCHAR(190).
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 190;

    public string $idempotencyKey;

    /**
     * Preserve existing short keys. Long composite keys are deterministically
     * reduced to a namespaced SHA-256 digest, preserving uniqueness without a
     * schema migration or truncating revision/artifact hashes.
     */
    public static function canonicalIdempotencyKey(string $key): string
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Agent task idempotency key cannot be empty.');
        }

        return strlen($key) <= self::MAX_IDEMPOTENCY_KEY_LENGTH
            ? $key
            : 'eng:sha256:v1:'.hash('sha256', $key);
    }

    public function __construct(
        public string $id,
        public string $featureId,
        public AgentRole $role,
        public string $objective,
        public array $inputs,
        public array $contextRefs,
        public array $constraints,
        public string $expectedOutputSchema,
        public array $completionCriteria,
        string $idempotencyKey,
        public array $inputSnapshot,
    ) {
        EngineeringId::assert($id);
        EngineeringId::assert($featureId);
        if (trim($objective) === '') throw new InvalidArgumentException('Agent task objective cannot be empty.');
        if (trim($expectedOutputSchema) === '') throw new InvalidArgumentException('Agent task output schema cannot be empty.');
        $this->idempotencyKey = self::canonicalIdempotencyKey($idempotencyKey);
    }
}
