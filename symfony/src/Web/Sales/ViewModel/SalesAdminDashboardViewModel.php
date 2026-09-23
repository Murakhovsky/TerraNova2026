<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesAdminDashboardViewModel
{
    /**
     * @param list<array<string,mixed>> $pipelines
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param list<array{label:string,href:string,copy:string}> $areas
     */
    public function __construct(
        public array $pipelines,
        public array $kpis,
        public array $areas,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
