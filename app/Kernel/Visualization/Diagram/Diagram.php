<?php
declare(strict_types=1);

namespace Kernel\Visualization\Diagram;

use InvalidArgumentException;

final readonly class Diagram
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public string $content,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Diagram id is required.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $this->type)) {
            throw new InvalidArgumentException(sprintf('Invalid diagram type: %s.', $this->type));
        }
    }
}
