<?php
declare(strict_types=1);

namespace Platform\Documents\Contract;

interface DocumentMutationReceiptInterface
{
    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool;
}
