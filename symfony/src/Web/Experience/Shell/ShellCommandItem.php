<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

final readonly class ShellCommandItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $kind = 'navigation',
        public ?string $hint = null,
    ) {
    }
}
