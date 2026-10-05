<?php
declare(strict_types=1);

namespace App\Engineering\Application\Observability;

interface EngineeringExecutionEventStoreInterface
{
    /** @param array<string,mixed> $details */
    public function append(
        string $featureId,
        string $workflowId,
        string $category,
        string $action,
        string $status,
        string $summary,
        array $details = [],
        ?string $agentRunId = null,
        ?string $correlationId = null,
        ?int $durationMs = null,
        ?string $error = null,
    ): void;

    /** @return list<array<string,mixed>> */
    public function forWorkflow(string $workflowId, int $limit = 500): array;
}
