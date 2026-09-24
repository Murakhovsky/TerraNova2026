<?php

declare(strict_types=1);

namespace App\Web\Workspace\ViewModel;

final readonly class AnalyticsDashboardViewModel
{
    /**
     * @param list<array{label:string,value:string,hint:string,href:?string}> $kpis
     * @param list<array{title:string,subtitle:string,meta:string}> $sources
     * @param list<array{title:string,subtitle:string,meta:string,href:string}> $properties
     */
    public function __construct(
        public int $days,
        public array $kpis,
        public AnalyticsGridViewModel $daily,
        public array $sources,
        public array $properties,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
