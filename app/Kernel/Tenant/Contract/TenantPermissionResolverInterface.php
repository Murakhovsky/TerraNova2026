<?php
declare(strict_types=1);

namespace Kernel\Tenant\Contract;

use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Tenant\Model\Permission;

interface TenantPermissionResolverInterface
{
    /** @return list<Permission> */
    public function permissionsFor(AuthenticatedIdentity $identity): array;
}
