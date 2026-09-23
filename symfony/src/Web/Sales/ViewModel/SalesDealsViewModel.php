<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final readonly class SalesDealsViewModel
{
    /**
     * @param list<DataGridColumn> $columns
     * @param list<DataGridFilter> $filters
     * @param list<UIAction> $rowActions
     */
    public function __construct(
        public DataGridQuery $query,
        public DataGridPage $page,
        public DataGridState $gridState,
        public array $columns,
        public array $filters,
        public array $rowActions,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->page->total === 0 ? 'empty' : 'normal';
    }
}
