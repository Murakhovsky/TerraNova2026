<?php

declare(strict_types=1);

namespace Kernel\Queue\Contract;

use Kernel\Queue\AsyncOperationProjection;

interface AsyncOperationReadModelInterface
{
    /** @return list<AsyncOperationProjection> */
    public function recentForOrganization(string $organizationId, int $limit = 50): array;

    public function find(string $organizationId, string $operationId): ?AsyncOperationProjection;
}
