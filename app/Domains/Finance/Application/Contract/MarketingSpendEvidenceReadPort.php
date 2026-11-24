<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/**
 * Finance-owned read port. Implementation is provided by the integration layer.
 * The caller supplies ONLY opaque Marketing refs, never spend data or proof claims.
 */
interface MarketingSpendEvidenceReadPort
{
    /** @return array<string,mixed> Current Marketing-owned source record */
    public function get(TenantContext $actor,string $accountId,string $snapshotId):array;
}
