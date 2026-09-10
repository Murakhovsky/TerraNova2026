<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

/**
 * Rich Sales Workspace projection used by manager/director UX.
 *
 * The stable read contract remains intentionally small. EPIC 2-specific
 * projections live here so UI evolution cannot break runtime consumers.
 */
interface SalesWorkspaceOperationalReadModelInterface extends SalesWorkspaceReadModelInterface
{
    /** @return list<array<string, mixed>> */
    public function communications(string $organizationId, int $dealId, int $limit = 50): array;

    /** @return list<array<string, mixed>> */
    public function approvals(string $organizationId, ?int $dealId = null, ?int $ownerId = null, int $limit = 50): array;

    /** @return array<string, mixed> */
    public function directorAnalytics(string $organizationId, int $days = 30): array;
}
