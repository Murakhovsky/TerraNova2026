<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Domain\Agent\EngineeringAgentTask;

interface EngineeringAgentRunStoreInterface
{
    public function start(string $workflowId, EngineeringAgentTask $task, string $traceId): string;
    public function complete(string $engineeringRunId, EngineeringAgentRunResult $result): void;
    public function fail(string $engineeringRunId, string $errorType, string $errorMessage, int $technicalRetry = 0): void;
    public function existsByIdempotencyKey(string $idempotencyKey): bool;
    public function failStaleRunning(string $featureId, \App\Engineering\Domain\Agent\AgentRole $role, int $staleAfterSeconds): int;

    /** @return array<string,mixed>|null */
    public function byIdempotencyKey(string $idempotencyKey): ?array;

    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;

    /** @return list<array<string,mixed>> */
    public function forWorkflow(string $workflowId): array;
}
