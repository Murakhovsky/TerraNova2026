<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Module\Contract\BulkModuleStateRepositoryInterface;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

final readonly class ActiveModuleResolver
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleStateRepositoryInterface $states,
        private ?ModuleLifecycleRepositoryInterface $installations = null,
    ) {
    }

    public function snapshot(string $organizationId): OrganizationModuleSnapshot
    {
        $overrides = $this->states instanceof BulkModuleStateRepositoryInterface
            ? $this->states->enabledOverrides($organizationId)
            : $this->fallbackEnabledOverrides($organizationId);

        $installations = [];
        foreach ($this->installations?->forOrganization($organizationId) ?? [] as $installation) {
            $installations[$installation->moduleId] = $installation;
        }

        return new OrganizationModuleSnapshot(
            $organizationId,
            $this->catalog,
            $overrides,
            $installations,
        );
    }

    public function isEnabled(string $organizationId, string $moduleId): bool
    {
        return $this->snapshot($organizationId)->isEnabled($moduleId);
    }

    public function isConfiguredEnabled(string $organizationId, string $moduleId): bool
    {
        return $this->snapshot($organizationId)->isConfiguredEnabled($moduleId);
    }

    public function isInstalled(string $organizationId, string $moduleId): bool
    {
        return $this->snapshot($organizationId)->isInstalled($moduleId);
    }

    public function isCurrent(string $organizationId, string $moduleId): bool
    {
        return $this->snapshot($organizationId)->isCurrent($moduleId);
    }

    /** @return list<ModuleManifest> */
    public function active(string $organizationId): array
    {
        return $this->snapshot($organizationId)->active();
    }

    /** @return array<string, mixed> */
    public function describeModule(string $organizationId, string $moduleId): array
    {
        return $this->snapshot($organizationId)->describeModule($moduleId);
    }

    /** @return list<array<string, mixed>> */
    public function describe(string $organizationId): array
    {
        return $this->snapshot($organizationId)->describe();
    }

    /** @return array<string, bool> */
    private function fallbackEnabledOverrides(string $organizationId): array
    {
        $overrides = [];
        foreach ($this->catalog->ids() as $moduleId) {
            $value = $this->states->enabledOverride($organizationId, $moduleId);
            if ($value !== null) {
                $overrides[$moduleId] = $value;
            }
        }

        return $overrides;
    }
}
