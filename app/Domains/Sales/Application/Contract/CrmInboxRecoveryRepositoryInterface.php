<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface CrmInboxRecoveryRepositoryInterface
{
    /** Redacted, tenant-scoped native observation. Lock requires active transaction. */
    public function status(string $organizationId,string $id,bool $lock=false): ?array;

    /** Only a verified DEAD item at the observed revision may be requeued. */
    public function requeueDead(string $organizationId,string $id,string $revision): bool;
}
