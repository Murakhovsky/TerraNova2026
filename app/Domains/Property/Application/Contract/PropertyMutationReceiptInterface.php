<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyMutationReceiptInterface
{
    /**
     * Atomically claims an idempotency key for the operation.
     * Returns true for the first caller and false for an exact replay.
     * Implementations must reject key reuse with a different fingerprint.
     */
    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool;
}
