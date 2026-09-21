<?php

declare(strict_types=1);

namespace App\Application\Experience\Search;

final readonly class EntitySearchHit
{
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $entityType,
        public string $entityId,
        public ?string $subtitle = null,
        public float $score = 90.0,
    ) {
    }
}
