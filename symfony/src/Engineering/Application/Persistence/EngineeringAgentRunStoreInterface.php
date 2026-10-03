<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Domain\Agent\EngineeringAgentTask;

interface EngineeringAgentRunStoreInterface
{
    public function recordCompleted(
        string $workflowId,
        EngineeringAgentTask $task,
        EngineeringAgentRunResult $result,
        string $traceId,
    ): void;

    public function existsByIdempotencyKey(string $idempotencyKey): bool;

    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;
}
