<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** The current canonical agent read model has tenant-wide, not per-user, ACLs. */
final class AgentWorkspaceAccess
{
    public static function allows(TenantContext $actor): bool
    {
        return $actor->isManager()
            && $actor->allows(TenantPermissions::ACCESS)
            && $actor->allows(TenantPermissions::MANAGE);
    }
}
