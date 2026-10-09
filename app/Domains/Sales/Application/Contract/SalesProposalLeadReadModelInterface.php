<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

/**
 * Narrow Sales-owned read port for downstream proposal generation.
 * Read access is tenant-bound by the implementation, never by a caller's
 * asserted lead ownership. Commands remain exclusively inside Sales.
 */
interface SalesProposalLeadReadModelInterface
{
    /** @return array<string,mixed>|null */
    public function lead(string $organizationId, int $leadId): ?array;
}
