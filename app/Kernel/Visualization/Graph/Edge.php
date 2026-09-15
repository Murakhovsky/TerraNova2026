<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;

final readonly class Edge
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $source,
        public string $target,
        public string $relation,
        public array $metadata = [],
    ) {
        foreach (['id' => $this->id, 'source' => $this->source, 'target' => $this->target] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Graph edge %s is required.', $field));
            }
        }
        if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $this->relation)) {
            throw new InvalidArgumentException(sprintf('Invalid graph relation: %s.', $this->relation));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'target' => $this->target,
            'relation' => $this->relation,
            'metadata' => $this->metadata,
        ];
    }
}
