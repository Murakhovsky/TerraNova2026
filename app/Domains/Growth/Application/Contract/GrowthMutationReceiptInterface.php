<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthMutationReceiptInterface
{
    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool;
}
