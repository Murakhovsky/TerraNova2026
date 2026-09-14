<?php
declare(strict_types=1);

namespace Kernel\Module;

use RuntimeException;

final class OrganizationModuleSnapshot
{
    /** @var array<string, ModuleInstallation> */
    private array $installations;

    /** @var array<string, bool> */
    private array $effectiveEnabled = [];

    /**
     * @param array<string, bool> $enabledOverrides
     * @param array<string, ModuleInstallation> $installations
     */
    public function __construct(
        private readonly string $organizationId,
        private readonly ModuleCatalog $catalog,
        private readonly array $enabledOverrides,
        array $installations,
    ) {
        $this->installations = $installations;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function isConfiguredEnabled(string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        return $this->enabledOverrides[$moduleId] ?? $this->catalog->get($moduleId)->enabledByDefault;
    }

    public function isInstalled(string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        $installation = $this->installations[$moduleId] ?? null;
        return $installation === null || $installation->isInstalled();
    }

    public function isCurrent(string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        $installation = $this->installations[$moduleId] ?? null;
        if ($installation === null) {
            // Pre-lifecycle tenants remain deployment-current until lifecycle state exists.
            return true;
        }
        if (!$installation->isInstalled()) {
            return false;
        }

        $manifest = $this->catalog->get($moduleId);
        return $installation->installedVersion === $manifest->version
            && $installation->schemaVersion === $manifest->schemaVersion;
    }

    public function isEnabled(string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }
        if (array_key_exists($moduleId, $this->effectiveEnabled)) {
            return $this->effectiveEnabled[$moduleId];
        }

        return $this->effectiveEnabled[$moduleId] = $this->resolve($moduleId, []);
    }

    /** @return list<ModuleManifest> */
    public function active(): array
    {
        return array_values(array_filter(
            $this->catalog->all(),
            fn (ModuleManifest $module): bool => $this->isEnabled($module->id),
        ));
    }

    /** @return array<string, mixed> */
    public function describeModule(string $moduleId): array
    {
        if (!$this->catalog->has($moduleId)) {
            throw new RuntimeException(sprintf('Unknown module: %s.', $moduleId));
        }

        $module = $this->catalog->get($moduleId);
        $installation = $this->installations[$moduleId] ?? null;
        $installed = $this->isInstalled($moduleId);
        $installedVersion = $installation?->installedVersion ?? $module->version;
        $installedSchemaVersion = $installation?->schemaVersion ?? $module->schemaVersion;
        $versionCurrent = $installed && $installedVersion === $module->version;
        $schemaVersionCurrent = $installed && $installedSchemaVersion === $module->schemaVersion;
        $active = $this->isEnabled($moduleId);

        return $module->toArray() + [
            'installed' => $installed,
            'installed_version' => $installedVersion,
            'installed_schema_version' => $installedSchemaVersion,
            'version_current' => $versionCurrent,
            'schema_version_current' => $schemaVersionCurrent,
            'current' => $versionCurrent && $schemaVersionCurrent,
            'enabled' => $active,
            'configured_enabled' => $this->isConfiguredEnabled($moduleId),
            'active' => $active,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        return array_map(
            fn (ModuleManifest $module): array => $this->describeModule($module->id),
            $this->catalog->all(),
        );
    }

    /** @param array<string, true> $stack */
    private function resolve(string $moduleId, array $stack): bool
    {
        if (isset($stack[$moduleId])) {
            throw new RuntimeException(sprintf('Circular module dependency detected at %s.', $moduleId));
        }
        if (!$this->isInstalled($moduleId)
            || !$this->isCurrent($moduleId)
            || !$this->isConfiguredEnabled($moduleId)) {
            return false;
        }

        $stack[$moduleId] = true;
        foreach ($this->catalog->get($moduleId)->dependencies as $dependencyId) {
            if (array_key_exists($dependencyId, $this->effectiveEnabled)) {
                if (!$this->effectiveEnabled[$dependencyId]) {
                    return false;
                }
                continue;
            }
            if (!$this->resolve($dependencyId, $stack)) {
                $this->effectiveEnabled[$dependencyId] = false;
                return false;
            }
            $this->effectiveEnabled[$dependencyId] = true;
        }

        return true;
    }
}
