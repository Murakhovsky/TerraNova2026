<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /**
     * @param list<string> $dependencies
     * @param array<string, string> $dependencyConstraints
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $description = '',
        public string $icon = '',
        public array $dependencies = [],
        public bool $enabledByDefault = true,
        public array $dependencyConstraints = [],
        public string $kernelConstraint = '*',
        public string $schemaVersion = '1.0.0',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->id)) {
            throw new InvalidArgumentException(sprintf('Invalid module id: %s.', $this->id));
        }
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Module name is required.');
        }

        VersionConstraint::assertVersion($this->version, sprintf('version for module %s', $this->id));
        VersionConstraint::assertVersion($this->schemaVersion, sprintf('schema version for module %s', $this->id));
        VersionConstraint::assertConstraint($this->kernelConstraint);

        if (count($this->dependencies) !== count(array_unique($this->dependencies))) {
            throw new InvalidArgumentException(sprintf('Module %s contains duplicate dependencies.', $this->id));
        }

        foreach ($this->dependencies as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9_]*$/', $dependency) || $dependency === $this->id) {
                throw new InvalidArgumentException(sprintf('Invalid dependency for module %s.', $this->id));
            }
        }

        foreach ($this->dependencyConstraints as $dependency => $constraint) {
            if (!in_array($dependency, $this->dependencies, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Module %s defines a version constraint for undeclared dependency %s.',
                    $this->id,
                    $dependency,
                ));
            }
            VersionConstraint::assertConstraint($constraint);
        }
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $dependencies = [];
        $constraints = [];

        foreach ((array) ($definition['dependencies'] ?? []) as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $dependencies[] = $value;
                continue;
            }
            if (is_string($key) && is_string($value)) {
                $dependencies[] = $key;
                $constraints[$key] = $value;
            }
        }

        foreach ((array) ($definition['requires'] ?? []) as $dependency => $constraint) {
            if (!is_string($dependency) || !is_string($constraint)) {
                continue;
            }
            if (!in_array($dependency, $dependencies, true)) {
                $dependencies[] = $dependency;
            }
            $constraints[$dependency] = $constraint;
        }

        return new self(
            (string) ($definition['id'] ?? ''),
            (string) ($definition['name'] ?? ''),
            (string) ($definition['version'] ?? ''),
            (string) ($definition['description'] ?? ''),
            (string) ($definition['icon'] ?? ''),
            array_values($dependencies),
            (bool) ($definition['enabled_by_default'] ?? true),
            $constraints,
            (string) ($definition['kernel_constraint'] ?? '*'),
            (string) ($definition['schema_version'] ?? '1.0.0'),
        );
    }

    public function constraintFor(string $dependency): string
    {
        return $this->dependencyConstraints[$dependency] ?? '*';
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
            'requires' => $this->dependencyConstraints,
            'kernel_constraint' => $this->kernelConstraint,
            'schema_version' => $this->schemaVersion,
            'enabled_by_default' => $this->enabledByDefault,
        ];
    }
}
