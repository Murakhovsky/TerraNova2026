<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;
use RuntimeException;

final class ModuleCatalog
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    /** @param iterable<ModuleManifest> $modules */
    public function __construct(iterable $modules)
    {
        foreach ($modules as $module) {
            if (isset($this->modules[$module->id])) {
                throw new InvalidArgumentException(sprintf('Module manifest is already registered: %s.', $module->id));
            }
            $this->modules[$module->id] = $module;
        }

        foreach ($this->modules as $module) {
            foreach ($module->dependencies as $dependency) {
                if (!isset($this->modules[$dependency])) {
                    throw new InvalidArgumentException(sprintf('Module %s depends on unknown module %s.', $module->id, $dependency));
                }
            }
        }
    }

    /** @return list<ModuleManifest> */
    public function all(): array
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
        return $this->modules[$id] ?? throw new RuntimeException(sprintf('Unknown module: %s.', $id));
    }
}
