<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/** Trusted owner readback only; never construct this payload from Action parameters.
 * Implementations must resolve native Marketing terminal journal, latest snapshot and
 * independently approved immutable provider document. No default permissive implementation.
 */
interface FinancePaidSettlementEvidencePort
{
    public function read(TenantContext $actor,string $reservationId,string $documentRef,string $snapshotRef):array;
}
