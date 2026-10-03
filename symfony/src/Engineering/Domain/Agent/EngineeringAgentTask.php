<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

use App\Engineering\Domain\Workflow\EngineeringId;
use InvalidArgumentException;

final readonly class EngineeringAgentTask
{
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
        public string $idempotencyKey,
        public array $inputSnapshot,
    ) {
        EngineeringId::assert($id);
        EngineeringId::assert($featureId);
        if (trim($objective) === '') throw new InvalidArgumentException('Agent task objective cannot be empty.');
        if (trim($expectedOutputSchema) === '') throw new InvalidArgumentException('Agent task output schema cannot be empty.');
        if (trim($idempotencyKey) === '') throw new InvalidArgumentException('Agent task idempotency key cannot be empty.');
    }
}
