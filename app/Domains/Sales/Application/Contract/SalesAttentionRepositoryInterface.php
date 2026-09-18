<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesAttentionRepositoryInterface
{
    /** @return list<string> */
    public function activeOrganizations(): array;

    public function inactiveDeals(string $organizationId, \DateTimeImmutable $cutoff, int $limit): array;

    public function missedFollowups(string $organizationId, \DateTimeImmutable $now, int $limit): array;

    public function stuckDeals(string $organizationId, \DateTimeImmutable $now, int $limit): array;

    public function claimSignal(string $organizationId, string $type, string $windowKey, string $mutationId): bool;
}
