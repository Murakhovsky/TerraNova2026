<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

use App\Web\Experience\Workspace\WorkspaceSlot;
use InvalidArgumentException;

final readonly class WorkspaceExtension
{
    /**
     * @param array<string,mixed> $props
     */
    public function __construct(
        public string $workspaceId,
        public WorkspaceSlot $slot,
        public string $template,
        public int $priority = 100,
        public array $props = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->workspaceId)) {
            throw new InvalidArgumentException('Workspace extension id must be a stable machine identifier.');
        }

        if (trim($this->template) === '' || str_contains($this->template, '..')) {
            throw new InvalidArgumentException('Workspace extension template must be a safe non-empty Twig path.');
        }
    }
}
