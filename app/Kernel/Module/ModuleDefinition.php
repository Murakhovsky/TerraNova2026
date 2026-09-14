<?php
declare(strict_types=1);

namespace Kernel\Module;

final readonly class ModuleDefinition
{
    public function __construct(
        public ModuleManifest $manifest,
        public ModuleContributions $contributions,
        public ?string $sourcePath = null,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition, ?string $sourcePath = null): self
    {
        $manifestDefinition = isset($definition['manifest']) && is_array($definition['manifest'])
            ? $definition['manifest']
            : $definition;

        $contributionDefinition = isset($definition['contributions']) && is_array($definition['contributions'])
            ? $definition['contributions']
            : [];

        return new self(
            ModuleManifest::fromArray($manifestDefinition),
            ModuleContributions::fromArray($contributionDefinition),
            $sourcePath,
        );
    }
}
