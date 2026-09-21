<?php

declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentActionProjection;
use Kernel\Agent\AgentRunProjection;

interface AgentRunReadModelInterface
{
    /** @return list<AgentRunProjection> */
    public function recentForOrganization(string $organizationId, int $limit = 20): array;

    /** @return list<AgentRunProjection> */
    public function recentForSubject(
        string $organizationId,
        string $subjectType,
        string $subjectId,
        int $limit = 20,
    ): array;

    public function find(string $organizationId, string $runId): ?AgentRunProjection;

    /** @return list<AgentActionProjection> */
    public function actionsForRun(string $organizationId, string $runId): array;
}
