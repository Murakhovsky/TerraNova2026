<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;

final readonly class Node
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public ?string $parent = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Graph node id is required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $this->type)) {
            throw new InvalidArgumentException(sprintf('Invalid graph node type: %s.', $this->type));
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException(sprintf('Graph node %s requires a label.', $this->id));
        }
        if ($this->parent !== null && trim($this->parent) === '') {
            throw new InvalidArgumentException(sprintf('Graph node %s has an empty parent id.', $this->id));
        }
        if ($this->parent === $this->id) {
            throw new InvalidArgumentException(sprintf('Graph node %s cannot be its own parent.', $this->id));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'parent' => $this->parent,
            'metadata' => $this->metadata,
        ];
    }
}
