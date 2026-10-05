<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

final readonly class ExperienceReleaseReport
{
    /**
     * @param array<string,mixed> $gates
     * @param list<string> $blockers
     */
    public function __construct(
        public string $status,
        public array $gates,
        public array $blockers,
    ) {}

    public function isReady(): bool
    {
        return $this->status === 'READY' && $this->blockers === [];
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'ready' => $this->isReady(),
            'gates' => $this->gates,
            'blockers' => $this->blockers,
        ];
    }
}
