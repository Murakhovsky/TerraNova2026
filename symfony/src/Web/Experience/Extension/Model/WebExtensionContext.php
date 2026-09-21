<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class WebExtensionContext
{
    public function __construct(
        public string $organizationId,
        public string $role,
        public string $surface = 'workspace',
        public string $activeSection = '',
        public string $activeItem = '',
    ) {
    }
}
