<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use RuntimeException;

final readonly class ActiveModuleResolver
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleStateRepositoryInterface $states,
        private ?ModuleLifecycleRepositoryInterface $installations = null,
    ) {
    }

    public function isEnabled(string $organizationId, string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        return $this->resolve($organizationId, $moduleId, []);
    }

    public function isConfiguredEnabled(string $organizationId, string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        $module = $this->catalog->get($moduleId);
        return $this->states->enabledOverride($organizationId, $moduleId) ?? $module->enabledByDefault;
    }

    public function isInstalled(string $organizationId, string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        $installation = $this->installations?->find($organizationId, $moduleId);
        return $installation === null || $installation->isInstalled();
    }

    public function isCurrent(string $organizationId, string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        $installation = $this->installations?->find($organizationId, $moduleId);
        if ($installation === null) {
            // Pre-lifecycle tenants are treated as deployment-current until an explicit
            // lifecycle record is created for them.
            return true;
        }
        if (!$installation->isInstalled()) {
            return false;
        }

        $manifest = $this->catalog->get($moduleId);
        return $installation->installedVersion === $manifest->version
            && $installation->schemaVersion === $manifest->schemaVersion;
    }

    /** @return list<ModuleManifest> */
    public function active(string $organizationId): array
    {
        return array_values(array_filter(
            $this->catalog->all(),
            fn (ModuleManifest $module): bool => $this->isEnabled($organizationId, $module->id),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function describe(string $organizationId): array
    {
        return array_map(
            function (ModuleManifest $module) use ($organizationId): array {
                $installation = $this->installations?->find($organizationId, $module->id);
                $active = $this->isEnabled($organizationId, $module->id);
                $installed = $this->isInstalled($organizationId, $module->id);
                $installedVersion = $installation?->installedVersion ?? $module->version;
                $installedSchemaVersion = $installation?->schemaVersion ?? $module->schemaVersion;
                $versionCurrent = $installed && $installedVersion === $module->version;
                $schemaVersionCurrent = $installed && $installedSchemaVersion === $module->schemaVersion;

                return $module->toArray() + [
                    // Missing lifecycle state means deployment-installed/current for pre-lifecycle tenants.
                    'installed' => $installed,
                    'installed_version' => $installedVersion,
                    'installed_schema_version' => $installedSchemaVersion,
                    'version_current' => $versionCurrent,
                    'schema_version_current' => $schemaVersionCurrent,
                    'current' => $versionCurrent && $schemaVersionCurrent,
                    // Keep `enabled` as the effective value for API backwards compatibility.
                    'enabled' => $active,
                    'configured_enabled' => $this->isConfiguredEnabled($organizationId, $module->id),
                    'active' => $active,
                ];
            },
            $this->catalog->all(),
        );
    }

    /** @param array<string, true> $stack */
    private function resolve(string $organizationId, string $moduleId, array $stack): bool
    {
        if (isset($stack[$moduleId])) {
            throw new RuntimeException(sprintf('Circular module dependency detected at %s.', $moduleId));
        }

        if (!$this->isInstalled($organizationId, $moduleId)
            || !$this->isCurrent($organizationId, $moduleId)
            || !$this->isConfiguredEnabled($organizationId, $moduleId)) {
            return false;
        }

        $stack[$moduleId] = true;
        foreach ($this->catalog->get($moduleId)->dependencies as $dependency) {
            if (!$this->resolve($organizationId, $dependency, $stack)) {
                return false;
            }
        }

        return true;
    }
}
