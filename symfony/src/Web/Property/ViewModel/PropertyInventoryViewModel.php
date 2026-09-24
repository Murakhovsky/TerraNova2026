<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final readonly class PropertyInventoryViewModel
{
    /**
     * @param list<DataGridColumn> $columns
     * @param list<DataGridFilter> $filters
     * @param array<string,int> $stats
     */
    public function __construct(
        public string $mode,
        public string $title,
        public string $subtitle,
        public string $baseUrl,
        public DataGridQuery $query,
        public DataGridPage $page,
        public DataGridState $gridState,
        public array $columns,
        public array $filters,
        public array $stats,
        public ?string $error=null,
    ) {
    }

    public function state(): string
    {
        if($this->error!==null)return 'error';
        return $this->page->total===0?'empty':'normal';
    }
}
