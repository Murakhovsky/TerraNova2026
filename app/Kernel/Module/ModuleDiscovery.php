<?php
declare(strict_types=1);

namespace Kernel\Module;

use RuntimeException;

final readonly class ModuleDiscovery
{
    public function __construct(private string $domainsPath)
    {
    }

    /** @return list<ModuleDefinition> */
    public function discover(): array
    {
        $paths = glob(rtrim($this->domainsPath, '/') . '/*/module.php') ?: [];
        sort($paths, SORT_STRING);

        $definitions = [];
        foreach ($paths as $path) {
            $definition = require $path;
            if (!is_array($definition)) {
                throw new RuntimeException(sprintf('Invalid module definition: %s.', $path));
            }
            $definitions[] = ModuleDefinition::fromArray($definition, $path);
        }

        usort(
            $definitions,
            static fn (ModuleDefinition $left, ModuleDefinition $right): int
                => $left->manifest->id <=> $right->manifest->id,
        );

        return $definitions;
    }
}
