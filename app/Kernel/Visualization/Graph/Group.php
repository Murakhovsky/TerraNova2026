<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;

final readonly class Group
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $parent = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Graph group id is required.');
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException(sprintf('Graph group %s requires a label.', $this->id));
        }
        if ($this->parent !== null && trim($this->parent) === '') {
            throw new InvalidArgumentException(sprintf('Graph group %s has an empty parent id.', $this->id));
        }
        if ($this->parent === $this->id) {
            throw new InvalidArgumentException(sprintf('Graph group %s cannot be its own parent.', $this->id));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'parent' => $this->parent,
            'metadata' => $this->metadata,
        ];
    }
}
