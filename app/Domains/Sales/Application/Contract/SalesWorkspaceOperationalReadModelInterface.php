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

    /**
     * Search is a workspace projection, not a new domain aggregate. It combines
     * tenant-scoped Leads, Deals and Persons into one navigation surface.
     *
     * @return array{query:string,items:list<array<string,mixed>>,groups:array<string,int>}
     */
    public function search(string $organizationId, string $query, int $limitPerType = 6): array;

    /** @return array<string, mixed> */
    public function directorAnalytics(string $organizationId, int $days = 30): array;
}
