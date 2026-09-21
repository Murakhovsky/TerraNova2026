<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class EntityLink
{
    public function __construct(
        public string $label,
        public string $path,
    ) {
    }
}
