<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class TenantUIActionPermissionChecker implements UIActionPermissionCheckerInterface
{
    public function supports(string $permission): bool
    {
        return in_array($permission, TenantPermissions::all(), true);
    }

    public function decide(
        string $permission,
        TenantContext $tenant,
        ?EntityRef $entity = null,
    ): UIActionPermissionDecision {
        if (!$this->supports($permission)) {
            throw new InvalidArgumentException('Unsupported tenant UIAction permission: ' . $permission);
        }

        return $tenant->allows($permission)
            ? UIActionPermissionDecision::allow()
            : UIActionPermissionDecision::deny('Required tenant permission is missing.');
    }
}
