<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesDirectorViewModel
{
    /**
     * @param array{history_days:int,forecast_days:int,pipeline_id:string} $filters
     * @param list<array{label:string,value:string}> $kpis
     * @param list<array{label:string,value:string}> $velocity
     * @param list<string> $qualityNotes
     */
    public function __construct(
        public array $filters,
        public array $kpis,
        public SalesDirectorGridViewModel $executiveGrid,
        public SalesDirectorGridViewModel $transitionGrid,
        public array $velocity,
        public SalesDirectorGridViewModel $managerGrid,
        public SalesDirectorGridViewModel $riskGrid,
        public array $qualityNotes,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
