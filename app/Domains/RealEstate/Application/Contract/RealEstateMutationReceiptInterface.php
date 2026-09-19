<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Contract;

interface RealEstateMutationReceiptInterface
{
    /**
     * Atomically claims an idempotency key.
     * Returns true for the first execution and false for an exact replay.
     * Reusing a key with another payload must fail.
     */
    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool;
}
