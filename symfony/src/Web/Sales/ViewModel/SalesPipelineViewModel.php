<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesPipelineViewModel
{
    /**
     * @param list<array{id:string,label:string}> $pipelineOptions
     * @param list<array{id:int,label:string}> $ownerOptions
     * @param array<string,string> $filters
     * @param list<array{id:string,label:string}> $stageOptions
     * @param list<array<string,mixed>> $stages
     */
    public function __construct(
        public string $pipelineId,
        public string $pipelineName,
        public array $pipelineOptions,
        public array $ownerOptions,
        public array $filters,
        public array $stageOptions,
        public array $stages,
        public int $visibleDeals,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->stages === [] ? 'empty' : 'normal';
    }
}
