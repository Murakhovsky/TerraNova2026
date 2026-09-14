<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyIntelligenceRepositoryInterface
{
    /** @param array<string,mixed> $snapshot @param list<array<string,mixed>> $comparables */
    public function save(string $organizationId, array $snapshot, array $comparables): void;

    /** @return array<string,mixed>|null */
    public function latest(string $organizationId, string $assetId): ?array;

    /** @return list<array<string,mixed>> */
    public function history(string $organizationId, string $assetId, int $limit = 20): array;
}
