<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

final readonly class ExperienceRouteDebtReport
{
    /** @param list<string> $missing @param list<string> $stale @param list<string> $mismatched */
    public function __construct(
        public array $missing,
        public array $stale,
        public array $mismatched,
    ) {}

    public function isGreen(): bool
    {
        return $this->missing === [] && $this->stale === [] && $this->mismatched === [];
    }

    public function toArray(): array
    {
        return [
            'green' => $this->isGreen(),
            'missing' => $this->missing,
            'stale' => $this->stale,
            'mismatched' => $this->mismatched,
        ];
    }
}
