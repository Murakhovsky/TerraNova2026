<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use RuntimeException;

final readonly class ModuleLifecycleManager
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleStateRepositoryInterface $states,
        private ModuleLifecycleRepositoryInterface $installations,
    ) {
    }

    public function install(string $organizationId, string $moduleId, bool $enable = true): void
    {
        $this->installRecursive($organizationId, $moduleId, $enable, []);
    }

    public function upgrade(string $organizationId, string $moduleId): void
    {
        $manifest = $this->catalog->get($moduleId);
        $installation = $this->installations->find($organizationId, $moduleId);

        if ($installation !== null && !$installation->isInstalled()) {
            throw new RuntimeException(sprintf('Module %s is uninstalled for organization %s.', $moduleId, $organizationId));
        }

        if ($installation !== null && version_compare($manifest->version, $installation->installedVersion, '<')) {
            throw new RuntimeException(sprintf(
                'Module downgrade is not allowed: %s %s -> %s.',
                $moduleId,
                $installation->installedVersion,
                $manifest->version,
            ));
        }

        foreach ($manifest->dependencies as $dependencyId) {
            $dependency = $this->installations->find($organizationId, $dependencyId);
            if ($dependency !== null && !$dependency->isInstalled()) {
                throw new RuntimeException(sprintf(
                    'Cannot upgrade %s while dependency %s is uninstalled.',
                    $moduleId,
                    $dependencyId,
                ));
            }
        }

        $this->installations->record($organizationId, $manifest, ModuleInstallation::INSTALLED);
    }

    public function uninstall(string $organizationId, string $moduleId): void
    {
        foreach ($this->catalog->all() as $candidate) {
            if (!in_array($moduleId, $candidate->dependencies, true)) {
                continue;
            }

            $installation = $this->installations->find($organizationId, $candidate->id);
            $enabled = $this->states->enabledOverride($organizationId, $candidate->id)
                ?? $candidate->enabledByDefault;

            if (($installation === null || $installation->isInstalled()) && $enabled) {
                throw new RuntimeException(sprintf(
                    'Cannot uninstall %s while dependent module %s is enabled.',
                    $moduleId,
                    $candidate->id,
                ));
            }
        }

        $manifest = $this->catalog->get($moduleId);
        $this->states->setEnabled($organizationId, $moduleId, false);
        $this->installations->record($organizationId, $manifest, ModuleInstallation::UNINSTALLED);
    }

    public function enable(string $organizationId, string $moduleId): void
    {
        $installation = $this->installations->find($organizationId, $moduleId);
        if ($installation !== null && !$installation->isInstalled()) {
            $this->install($organizationId, $moduleId, true);
            return;
        }

        $manifest = $this->catalog->get($moduleId);
        foreach ($manifest->dependencies as $dependencyId) {
            $dependency = $this->installations->find($organizationId, $dependencyId);
            if ($dependency !== null && !$dependency->isInstalled()) {
                $this->install($organizationId, $dependencyId, true);
            } else {
                $this->states->setEnabled($organizationId, $dependencyId, true);
            }
        }

        $this->states->setEnabled($organizationId, $moduleId, true);
    }

    public function disable(string $organizationId, string $moduleId): void
    {
        $this->catalog->get($moduleId);
        $this->states->setEnabled($organizationId, $moduleId, false);
    }

    /** @return list<string> */
    public function migrationPlan(string $moduleId): array
    {
        return $this->catalog->definition($moduleId)->contributions->migrationFiles;
    }

    /** @param array<string, true> $stack */
    private function installRecursive(string $organizationId, string $moduleId, bool $enable, array $stack): void
    {
        if (isset($stack[$moduleId])) {
            throw new RuntimeException(sprintf('Circular install dependency detected at %s.', $moduleId));
        }

        $stack[$moduleId] = true;
        $manifest = $this->catalog->get($moduleId);

        foreach ($manifest->dependencies as $dependencyId) {
            $this->installRecursive($organizationId, $dependencyId, true, $stack);
        }

        $this->installations->record($organizationId, $manifest, ModuleInstallation::INSTALLED);
        $this->states->setEnabled($organizationId, $moduleId, $enable);
    }
}
