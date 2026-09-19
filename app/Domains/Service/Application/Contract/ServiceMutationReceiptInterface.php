<?php
declare(strict_types=1);

namespace Domains\Service\Application\Contract;

interface ServiceMutationReceiptInterface
{
    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool;
}
