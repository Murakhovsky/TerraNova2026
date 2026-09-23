<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesLeadsViewModel
{
    /**
     * @param list<array<string,mixed>> $items
     * @param list<array{id:int,label:string}> $owners
     * @param array{q:string,status:string,source:string} $filters
     */
    public function __construct(
        public array $items,
        public array $owners,
        public array $filters,
        public int $page,
        public int $perPage,
        public bool $hasMore,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->items === [] ? 'empty' : 'normal';
    }

    public function nextPageUrl(): ?string
    {
        if (!$this->hasMore) {
            return null;
        }

        return '/sales/leads?' . http_build_query([
            'page' => $this->page + 1,
            'q' => $this->filters['q'],
            'status' => $this->filters['status'],
            'source' => $this->filters['source'],
        ]);
    }
}
