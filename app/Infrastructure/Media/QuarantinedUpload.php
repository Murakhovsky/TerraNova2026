<?php

declare(strict_types=1);

namespace Infrastructure\Media;

use RuntimeException;

final class QuarantinedUpload
{
    private bool $released = false;

    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $checksum,
    ) {
    }

    public function releaseTo(string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload destination directory.');
        }

        if (!rename($this->path, $destination) && !copy($this->path, $destination)) {
            throw new RuntimeException('Could not release quarantined upload to storage.');
        }

        @unlink($this->path);
        $this->released = true;
    }

    public function discard(): void
    {
        if (!$this->released && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->discard();
    }
}
