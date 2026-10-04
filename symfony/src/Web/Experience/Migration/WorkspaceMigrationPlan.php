<?php
declare(strict_types=1);

namespace App\Web\Experience\Migration;

final readonly class WorkspaceMigrationPlan
{
    /**
     * @param list<array<string,mixed>> $stages
     */
    public function __construct(
        public bool $blocked,
        public string $blockedReason,
        public array $stages,
        public int $pages,
    ) {}

    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked,
            'blocked_reason' => $this->blockedReason,
            'pages' => $this->pages,
            'stages' => $this->stages,
        ];
    }
}
