<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesDashboardViewModel
{
    /**
     * @param list<array<string,mixed>> $atRisk
     * @param list<array<string,mixed>> $nextActions
     * @param list<array<string,mixed>> $newLeads
     */
    public function __construct(
        public string $activeDeals,
        public string $pipelineValue,
        public string $expectedRevenue,
        public string $dealsAtRisk,
        public array $atRisk,
        public array $nextActions,
        public array $newLeads,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
