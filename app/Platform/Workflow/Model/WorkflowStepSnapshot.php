<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use DomainException;

/**
 * Immutable persisted step projection. This is not a successful Action receipt.
 */
final readonly class WorkflowStepSnapshot
{
    public function __construct(
        public string $stepId,
        public string $capabilityId,
        public string $capabilityVersion,
        public string $sideEffectLevel,
        public string $state,
        public int $attempts = 0,
        public int $revision = 1,
        public ?string $resultReference = null,
        public ?string $claimToken = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([$stepId, $capabilityId, $capabilityVersion, $sideEffectLevel, $state] as $field) {
            if ($field === '') {
                throw new DomainException('Workflow persisted step fields cannot be empty.');
            }
        }
        if ($attempts < 0 || $revision < 1
            || ($idempotencyKey !== null && !preg_match('/^[a-f0-9]{64}$/', $idempotencyKey))) {
            throw new DomainException('Workflow persisted step version/attempt is invalid.');
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromPersistedRow(array $row): self
    {
        foreach (['step_id','capability_id','capability_version','side_effect_level','state'] as $key) {
            if (!is_string($row[$key] ?? null) || $row[$key] === '') {
                throw new DomainException('Workflow persisted step has invalid ' . $key . '.');
            }
        }
        return new self($row['step_id'], $row['capability_id'], $row['capability_version'],
            $row['side_effect_level'], $row['state']);
    }
}
