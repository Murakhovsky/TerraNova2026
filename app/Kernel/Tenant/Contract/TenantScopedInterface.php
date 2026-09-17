<?php
declare(strict_types=1);

namespace Kernel\Tenant\Contract;

use Kernel\Shared\Domain\OrganizationId;

interface TenantScopedInterface
{
    public function organizationId(): OrganizationId;
}
