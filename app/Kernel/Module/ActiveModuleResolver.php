<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use RuntimeException;

final readonly class ActiveModuleResolver
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ModuleStateRepositoryInterface $states,
    ) {
    }

    public function isEnabled(string $organizationId, string $moduleId): bool
    {
        if (!$this->catalog->has($moduleId)) {
            return false;
        }

        return $this->resolve($organizationId, $moduleId, []);
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
            fn (ModuleManifest $module): array => $module->toArray() + [
                'installed' => true,
                'enabled' => $this->isEnabled($organizationId, $module->id),
            ],
            $this->catalog->all(),
        );
    }

    /** @param array<string, true> $stack */
    private function resolve(string $organizationId, string $moduleId, array $stack): bool
    {
        if (isset($stack[$moduleId])) {
            throw new RuntimeException(sprintf('Circular module dependency detected at %s.', $moduleId));
        }

        $module = $this->catalog->get($moduleId);
        $override = $this->states->enabledOverride($organizationId, $moduleId);
        if (!($override ?? $module->enabledByDefault)) {
            return false;
        }

        $stack[$moduleId] = true;
        foreach ($module->dependencies as $dependency) {
            if (!$this->resolve($organizationId, $dependency, $stack)) {
                return false;
            }
        }

        return true;
    }
}
