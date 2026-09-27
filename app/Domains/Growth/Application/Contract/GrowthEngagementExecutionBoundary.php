<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementExecutionBoundary
{
    /** @return array<string,mixed> */
    public function proposeMessageAction(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $candidateId,
        string $recommendationId,
        string $body,
        string $idempotencyKey,
    ):array;

    /** @return array<string,mixed> */
    public function executionBrief(
        string $organizationId,
        string $candidateId,
        string $recommendationId,
    ):array;
}
