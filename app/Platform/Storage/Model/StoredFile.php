<?php
declare(strict_types=1);

namespace Platform\Storage\Model;

use InvalidArgumentException;

final readonly class StoredFile
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $key,
        public int $size,
        public string $sha256,
        public ?string $contentType = null,
        public array $metadata = [],
    ) {
        if (trim($this->key) === '' || $this->size < 0 || !preg_match('/^[a-f0-9]{64}$/', $this->sha256)) {
            throw new InvalidArgumentException('Stored file metadata is invalid.');
        }
    }
}
