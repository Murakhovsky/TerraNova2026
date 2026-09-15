<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use InvalidArgumentException;

final readonly class ArchitectureProjectionDefinition
{
    /**
     * @param list<string> $nodeTypes
     * @param list<string> $relations
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $nodeTypes,
        public array $relations,
        public ?int $defaultDepth = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->name)) {
            throw new InvalidArgumentException(sprintf('Invalid architecture projection name: %s.', $this->name));
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException('Architecture projection label is required.');
        }
        if ($this->nodeTypes === []) {
            throw new InvalidArgumentException(sprintf('Architecture projection %s must declare node types.', $this->name));
        }
        if ($this->defaultDepth !== null && $this->defaultDepth < 0) {
            throw new InvalidArgumentException(sprintf('Architecture projection %s has invalid default depth.', $this->name));
        }
    }
}
