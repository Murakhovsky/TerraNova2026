<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Domain\Agent\AgentRole;

interface EngineeringFindingStoreInterface
{
    public function recordFindings(
        string $featureId,
        AgentRole $sourceRole,
        array $findings,
        ?string $agentRunId = null,
        ?string $taskId = null,
    ): void;

    public function resolveOpenForSource(string $featureId, AgentRole $sourceRole, string $resolvedByRunId): void;
    public function hasOpenCritical(string $featureId): bool;

    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;
}
