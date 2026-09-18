<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesWriteServiceFactoryInterface
{
    public function forOrganization(string $organizationId): SalesWriteServiceInterface;
}
