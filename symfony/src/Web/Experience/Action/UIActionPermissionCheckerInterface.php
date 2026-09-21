<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use App\Web\Experience\Model\EntityRef;
use Kernel\Tenant\Model\TenantContext;

interface UIActionPermissionCheckerInterface
{
    public function supports(string $permission): bool;

    public function decide(
        string $permission,
        TenantContext $tenant,
        ?EntityRef $entity = null,
    ): UIActionPermissionDecision;
}
