<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthHandoffBoundary
{
    /** @return array<string,mixed> */
    public function dispatch(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $candidateId,
        string $idempotencyKey,
    ): array;

    /** @return array<string,mixed> */
    public function handoffBrief(string $organizationId,string $candidateId): array;

    /** @return list<string> */
    public function targets(): array;
}
