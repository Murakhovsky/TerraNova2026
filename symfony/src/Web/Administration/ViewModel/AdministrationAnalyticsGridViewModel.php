<?php

declare(strict_types=1);

namespace App\Web\Administration\ViewModel;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final readonly class AdministrationAnalyticsGridViewModel
{
    /** @param list<DataGridColumn> $columns */
    public function __construct(
        public DataGridQuery $query,
        public DataGridPage $page,
        public array $columns,
        public DataGridState $state,
    ) {
    }
}
