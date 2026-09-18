<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesMutationReceiptRepositoryInterface
{
    public function find(string $organizationId, string $operationType, string $idempotencyKey): ?string;

    public function claim(string $organizationId, string $operationType, string $idempotencyKey, string $pendingMutationId): bool;

    public function complete(
        string $organizationId,
        string $operationType,
        string $idempotencyKey,
        string $pendingMutationId,
        string $mutationId,
    ): bool;
}
