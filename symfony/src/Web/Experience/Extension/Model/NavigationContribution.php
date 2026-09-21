<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class NavigationContribution
{
    public function __construct(
        public string $key,
        public string $label,
        public string $path,
        public string $glyph = '•',
        public int $priority = 100,
        public ?string $parentKey = null,
    ) {
    }
}
