<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $description = '',
        public string $icon = '',
        public array $dependencies = [],
        public bool $enabledByDefault = true,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->id)) {
            throw new InvalidArgumentException(sprintf('Invalid module id: %s.', $this->id));
        }
        if (trim($this->name) === '' || trim($this->version) === '') {
            throw new InvalidArgumentException('Module name and version are required.');
        }
        if (count($this->dependencies) !== count(array_unique($this->dependencies))) {
            throw new InvalidArgumentException(sprintf('Module %s contains duplicate dependencies.', $this->id));
        }
        foreach ($this->dependencies as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9_]*$/', $dependency) || $dependency === $this->id) {
                throw new InvalidArgumentException(sprintf('Invalid dependency for module %s.', $this->id));
            }
        }
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['id'] ?? ''),
            (string) ($definition['name'] ?? ''),
            (string) ($definition['version'] ?? ''),
            (string) ($definition['description'] ?? ''),
            (string) ($definition['icon'] ?? ''),
            array_values(array_filter((array) ($definition['dependencies'] ?? []), 'is_string')),
            (bool) ($definition['enabled_by_default'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'icon' => $this->icon,
            'dependencies' => $this->dependencies,
            'enabled_by_default' => $this->enabledByDefault,
        ];
    }
}
