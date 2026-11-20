<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/**
 * Internal bridge port for Kernel-approved Finance Actions only.
 * Does not accept caller-controlled provider money, tenant proofs, or credentials.
 */
interface ApprovedMarketingSpendEvidenceReadPort
{
    /** @return array<string,mixed> A single current Marketing-owned source snapshot */
    public function getForApprovedAction(string $organizationId,string $adAccountRef,string $sourceSnapshotRef):array;
}
