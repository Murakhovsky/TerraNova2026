<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

use InvalidArgumentException;

final readonly class ShellCommandItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $kind = 'navigation',
        public ?string $hint = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $this->id)) {
            throw new InvalidArgumentException('ShellCommandItem id must be a stable namespaced identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('ShellCommandItem label must not be empty.');
        }

        if (!str_starts_with($this->path, '/') || str_starts_with($this->path, '//')) {
            throw new InvalidArgumentException('ShellCommandItem path must be an application-local absolute path.');
        }

        if (!preg_match('/^[a-z][a-z0-9._-]*$/', $this->kind)) {
            throw new InvalidArgumentException('ShellCommandItem kind must be a stable lowercase identifier.');
        }
    }
}
