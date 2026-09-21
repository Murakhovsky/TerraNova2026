<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

final readonly class ShellBreadcrumb
{
    public function __construct(
        public string $label,
        public ?string $path = null,
    ) {
    }
}
