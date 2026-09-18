<?php
declare(strict_types=1);

namespace Infrastructure\Storage;

use Platform\Storage\Contract\FileStorageInterface;
use Platform\Storage\Model\StoredFile;
use RuntimeException;

final readonly class LocalFileStorage implements FileStorageInterface
{
    public function __construct(private string $rootDirectory)
    {
        if (trim($this->rootDirectory) === '') {
            throw new RuntimeException('Local file storage root directory cannot be empty.');
        }
    }

    public function put(string $key, string $contents, ?string $contentType = null, array $metadata = []): StoredFile
    {
        $normalized = $this->normalizeKey($key);
        $path = $this->path($normalized);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create storage directory.');
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write stored file.');
        }

        return new StoredFile(
            $normalized,
            strlen($contents),
            hash('sha256', $contents),
            $contentType,
            $metadata,
        );
    }

    public function read(string $key): string
    {
        $path = $this->path($this->normalizeKey($key));
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Stored file does not exist or cannot be read.');
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($this->normalizeKey($key)));
    }

    public function delete(string $key): void
    {
        $path = $this->path($this->normalizeKey($key));
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Cannot delete stored file.');
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key, " \t\n\r\0\x0B/");
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '\\')) {
            throw new RuntimeException('Invalid storage key.');
        }

        $segments = explode('/', preg_replace('#/+#', '/', $key) ?? $key);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Storage key cannot contain traversal segments.');
            }
        }

        return implode('/', $segments);
    }
}
