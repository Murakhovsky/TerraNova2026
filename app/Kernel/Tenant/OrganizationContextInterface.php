<?php
declare(strict_types=1);

namespace Kernel\Tenant;

/**
 * @deprecated Legacy delivery-layer bridge. New code must use
 * Kernel\Tenant\Contract\TenantContextProviderInterface and TenantContext.
 */
interface OrganizationContextInterface
{
    public function id(): string;

    public function actorId(): string;

    public function isAuthenticated(): bool;
}
