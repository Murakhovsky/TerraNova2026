<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final class ModuleCapabilityRegistry
{
    /** @var array<string, string> capability => module id */
    private array $owners = [];

    public function __construct(private readonly ModuleCatalog $catalog)
    {
        foreach ($catalog->definitions() as $definition) {
            foreach ($definition->contributions->capabilities as $capability) {
                if (isset($this->owners[$capability])) {
                    throw new InvalidArgumentException(sprintf(
                        'Capability %s is contributed by both %s and %s.',
                        $capability,
                        $this->owners[$capability],
                        $definition->manifest->id,
                    ));
                }
                $this->owners[$capability] = $definition->manifest->id;
            }
        }
    }

    public function moduleFor(string $capability): ?string
    {
        return $this->owners[$capability] ?? null;
    }

    /** @return list<string> */
    public function capabilitiesFor(string $moduleId): array
    {
        $this->catalog->get($moduleId);

        return array_keys(array_filter(
            $this->owners,
            static fn (string $owner): bool => $owner === $moduleId,
        ));
    }

    public function isAvailable(
        string $organizationId,
        string $capability,
        ActiveModuleResolver $modules,
    ): bool {
        $moduleId = $this->moduleFor($capability);

        return $moduleId !== null && $modules->isEnabled($organizationId, $moduleId);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->owners;
    }
}
