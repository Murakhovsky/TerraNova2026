<?php
declare(strict_types=1);

namespace Kernel\Tenant\Service;

use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Tenant\Contract\TenantPermissionResolverInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class TenantContextFactory
{
    public function __construct(private TenantPermissionResolverInterface $permissions)
    {
    }

    public function fromIdentity(AuthenticatedIdentity $identity): TenantContext
    {
        return new TenantContext(
            $identity->userId(),
            $identity->organizationId(),
            $identity->role(),
            $this->permissions->permissionsFor($identity),
        );
    }
}
