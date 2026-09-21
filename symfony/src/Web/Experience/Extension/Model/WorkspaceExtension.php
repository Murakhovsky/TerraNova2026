<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

final readonly class WorkspaceExtension
{
    public function __construct(
        public string $workspaceId,
        public string $slot,
        public string $template,
        public int $priority = 100,
    ) {
    }
}
