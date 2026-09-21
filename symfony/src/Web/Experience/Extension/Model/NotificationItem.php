<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class NotificationItem
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $body = null,
        public string $severity = 'info',
        public ?string $path = null,
    ) {
    }
}
