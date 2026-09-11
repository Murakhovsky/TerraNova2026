<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesPipelineGovernanceInterface
{
    /** @return array<string, mixed> */
    public function cloneToDraft(
        string $organizationId,
        string $sourcePipelineId,
        array $input,
        string $actorId,
    ): array;

    /** @return list<array<string, mixed>> */
    public function revisions(string $organizationId, string $pipelineId, int $limit = 100): array;
}
