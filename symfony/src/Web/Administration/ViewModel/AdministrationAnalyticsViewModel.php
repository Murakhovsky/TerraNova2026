<?php

declare(strict_types=1);

namespace App\Web\Administration\ViewModel;

final readonly class AdministrationAnalyticsViewModel
{
    /**
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param list<array{title:string,subtitle:string,meta:string}> $sources
     */
    public function __construct(
        public int $days,
        public array $kpis,
        public AdministrationAnalyticsGridViewModel $dailyGrid,
        public AdministrationAnalyticsGridViewModel $propertyGrid,
        public array $sources,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
