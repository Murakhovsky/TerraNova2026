<?php

declare(strict_types=1);

namespace App\Web\Workspace\ViewModel;

final readonly class ExecutiveDashboardViewModel
{
    /**
     * @param list<array<string,mixed>> $metrics
     * @param list<array<string,mixed>> $salesAttention
     * @param list<array<string,mixed>> $cosAttention
     * @param list<array<string,mixed>> $leads
     * @param list<array<string,mixed>> $properties
     * @param list<array<string,mixed>> $modules
     * @param list<array<string,mixed>> $decisions
     * @param array<string,array{state:string,title:string,copy:string}> $sectionStates
     */
    public function __construct(
        public string $generatedAt,
        public array $metrics,
        public array $salesAttention,
        public array $cosAttention,
        public array $leads,
        public array $properties,
        public array $modules,
        public array $decisions,
        public array $sectionStates,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
