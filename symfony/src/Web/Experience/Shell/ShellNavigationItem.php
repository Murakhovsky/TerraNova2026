<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

final readonly class ShellNavigationItem
{
    /**
     * @param list<ShellNavigationItem> $children
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $path,
        public string $glyph = '•',
        public bool $active = false,
        public array $children = [],
    ) {
    }
}
