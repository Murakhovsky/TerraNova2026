<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;
use RuntimeException;

final class ModuleCatalog
{
    /** @var array<string, ModuleDefinition> */
    private array $modules = [];

    /** @param iterable<ModuleDefinition|ModuleManifest> $modules */
    public function __construct(iterable $modules)
    {
        foreach ($modules as $module) {
            $definition = $module instanceof ModuleManifest
                ? new ModuleDefinition($module, new ModuleContributions())
                : $module;

            if (!$definition instanceof ModuleDefinition) {
                throw new InvalidArgumentException('ModuleCatalog accepts only ModuleDefinition or ModuleManifest instances.');
            }

            $id = $definition->manifest->id;
            if (isset($this->modules[$id])) {
                throw new InvalidArgumentException(sprintf('Module manifest is already registered: %s.', $id));
            }
            $this->modules[$id] = $definition;
        }

        $this->assertDependencies();
        $this->assertAcyclic();
        $this->assertCompatibility(KernelVersion::VERSION);
    }

    /** @return list<ModuleManifest> */
    public function all(): array
    {
        return array_map(
            static fn (ModuleDefinition $definition): ModuleManifest => $definition->manifest,
            array_values($this->modules),
        );
    }

    /** @return list<ModuleDefinition> */
    public function definitions(): array
    {
        return array_values($this->modules);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->modules);
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function get(string $id): ModuleManifest
    {
        return $this->definition($id)->manifest;
    }

    public function definition(string $id): ModuleDefinition
    {
        return $this->modules[$id] ?? throw new RuntimeException(sprintf('Unknown module: %s.', $id));
    }

    public function assertCompatibility(string $kernelVersion): void
    {
        VersionConstraint::assertVersion($kernelVersion, 'Kernel version');

        foreach ($this->modules as $definition) {
            $module = $definition->manifest;

            if (!VersionConstraint::matches($kernelVersion, $module->kernelConstraint)) {
                throw new InvalidArgumentException(sprintf(
                    'Module %s %s requires Kernel %s, current Kernel is %s.',
                    $module->id,
                    $module->version,
                    $module->kernelConstraint,
                    $kernelVersion,
                ));
            }

            foreach ($module->dependencies as $dependencyId) {
                $dependency = $this->modules[$dependencyId]->manifest;
                $constraint = $module->constraintFor($dependencyId);
                if (!VersionConstraint::matches($dependency->version, $constraint)) {
                    throw new InvalidArgumentException(sprintf(
                        'Module %s %s requires %s %s, installed version is %s.',
                        $module->id,
                        $module->version,
                        $dependencyId,
                        $constraint,
                        $dependency->version,
                    ));
                }
            }
        }
    }

    private function assertDependencies(): void
    {
        foreach ($this->modules as $definition) {
            $module = $definition->manifest;
            foreach ($module->dependencies as $dependency) {
                if (!isset($this->modules[$dependency])) {
                    throw new InvalidArgumentException(sprintf(
                        'Module %s depends on unknown module %s.',
                        $module->id,
                        $dependency,
                    ));
                }
            }
        }
    }

    private function assertAcyclic(): void
    {
        $visited = [];
        $active = [];

        foreach (array_keys($this->modules) as $moduleId) {
            $this->visit($moduleId, $visited, $active);
        }
    }

    /** @param array<string, true> $visited @param array<string, true> $active */
    private function visit(string $moduleId, array &$visited, array &$active): void
    {
        if (isset($visited[$moduleId])) {
            return;
        }
        if (isset($active[$moduleId])) {
            throw new InvalidArgumentException(sprintf('Circular module dependency detected at %s.', $moduleId));
        }

        $active[$moduleId] = true;
        foreach ($this->modules[$moduleId]->manifest->dependencies as $dependencyId) {
            $this->visit($dependencyId, $visited, $active);
        }
        unset($active[$moduleId]);
        $visited[$moduleId] = true;
    }
}
