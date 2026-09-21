<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

use App\Web\Experience\Model\EntityRef;

final readonly class SearchResult
{
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $kind = 'entity',
        public ?string $subtitle = null,
        public ?EntityRef $entity = null,
        public float $score = 0.0,
    ) {
    }
}
