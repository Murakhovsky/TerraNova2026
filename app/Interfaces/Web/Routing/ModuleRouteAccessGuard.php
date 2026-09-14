<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\OrganizationContextInterface;

final readonly class ModuleRouteAccessGuard
{
    public function __construct(
        private OrganizationContextInterface $organization,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function allows(string $moduleId): bool
    {
        return $this->modules->isEnabled($this->organization->id(), $moduleId);
    }
}
