<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class ActivityItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public ?string $path = null,
    ) {
    }
}
