<?php

declare(strict_types=1);

namespace App\Web\Visualization\ViewModel;

final readonly class ArchitectureOverviewViewModel
{
    /**
     * @param list<array{name:string,label:string,layout:string}> $views
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $domains
     * @param array<string,mixed> $health
     */
    public function __construct(
        public string $defaultView,
        public array $views,
        public array $summary,
        public array $domains,
        public array $health,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }

    public function healthTone(): string
    {
        return match (strtolower((string) ($this->health['status'] ?? 'unknown'))) {
            'ok', 'healthy' => 'positive',
            'error', 'failed' => 'danger',
            default => 'warning',
        };
    }
}
