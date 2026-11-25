<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Trusted in-process, tenant-bound read port. Financial evidence belongs to Finance. */
interface SalesCommerceOrderLifecycleEvidencePort
{
    /**
     * Read immutable lifecycle facts after an exclusive revision cursor (placement is revision 1).
     * Null means no order in the requested tenant. No actor, raw source reference or payment data.
     * Consumers must deduplicate by event_id and advance only after durable processing.
     */
    public function lifecycleEvidence(string $organizationId,string $orderId,int $afterRevision=1,int $limit=100):?array;
}
