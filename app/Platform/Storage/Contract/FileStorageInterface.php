<?php
declare(strict_types=1);

namespace Platform\Storage\Contract;

use Platform\Storage\Model\StoredFile;

interface FileStorageInterface
{
    /** @param array<string, scalar|null> $metadata */
    public function put(string $key, string $contents, ?string $contentType = null, array $metadata = []): StoredFile;

    public function read(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;
}
