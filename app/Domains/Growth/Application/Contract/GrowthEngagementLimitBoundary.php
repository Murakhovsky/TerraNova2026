<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementLimitBoundary
{
    /** @return array<string,mixed> */
    public function view(string $organizationId):array;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
        array $input,
    ):array;
}
