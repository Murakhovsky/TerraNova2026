<?php

declare(strict_types=1);

namespace App\Web\Content\ViewModel;

final readonly class ContentAdministrationViewModel
{
    /**
     * @param array{q:string,type:string,status:string} $filters
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param array<string,string> $typeLabels
     * @param array<string,string> $statusLabels
     * @param list<array<string,string>> $deliveries
     */
    public function __construct(
        public array $filters,
        public array $kpis,
        public array $typeLabels,
        public array $statusLabels,
        public ContentAdministrationGridViewModel $grid,
        public array $deliveries,
        public int $sentDeliveries,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) return 'error';
        return $this->grid->page->rows === [] ? 'empty' : 'normal';
    }
}
