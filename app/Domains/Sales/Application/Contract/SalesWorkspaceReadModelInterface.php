<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesWorkspaceReadModelInterface
{
    /** @return array<string, mixed> */
    public function dashboard(string $organizationId, ?int $ownerId = null): array;

    /** @return list<array<string, mixed>> */
    public function leads(string $organizationId, array $filters = []): array;

    /** @return list<array<string, mixed>> */
    public function deals(string $organizationId, array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function deal(string $organizationId, int $dealId): ?array;

    /** @return list<array<string, mixed>> */
    public function timeline(string $organizationId, int $dealId, int $limit = 100): array;

    /** @return list<array<string, mixed>> */
    public function pipelines(string $organizationId): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function today(string $organizationId, int $ownerId): array;

    /** @return array<string, mixed> */
    public function metrics(string $organizationId, int $days = 30): array;
}
