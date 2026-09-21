<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class DashboardWidget
{
    public function __construct(
        public string $id,
        public string $title,
        public string $template,
        public int $priority = 100,
    ) {
    }
}
