<?php
declare(strict_types=1);

namespace App\Security;

use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Tenant\Contract\TenantPermissionResolverInterface;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class RoleTenantPermissionResolver implements TenantPermissionResolverInterface
{
    public function permissionsFor(AuthenticatedIdentity $identity): array
    {
        $permissions = [Permission::fromString(TenantPermissions::ACCESS)];

        if ($identity->isManager()) {
            $permissions[] = Permission::fromString(TenantPermissions::MANAGE);
        }

        if ($identity->isAdmin()) {
            $permissions[] = Permission::fromString(TenantPermissions::ADMIN);
        }

        return $permissions;
    }
}
