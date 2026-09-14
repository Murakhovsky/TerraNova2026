<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesAccessControlInterface
{
    public function hasCapability(string $organizationId, int $userId, string $capability): bool;
}
