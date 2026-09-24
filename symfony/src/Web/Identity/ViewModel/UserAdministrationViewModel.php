<?php

declare(strict_types=1);

namespace App\Web\Identity\ViewModel;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final readonly class UserAdministrationViewModel
{
    /**
     * @param array{q:string,role:string,status:string,sort:string} $filters
     * @param list<array{label:string,value:string,hint:string,href:string}> $kpis
     * @param array<string,string> $roleOptions
     * @param array<string,string> $statusOptions
     * @param array<string,string> $sortOptions
     * @param list<array<string,mixed>> $users
     * @param list<DataGridColumn> $capabilityColumns
     */
    public function __construct(
        public array $filters,
        public array $kpis,
        public array $roleOptions,
        public array $statusOptions,
        public array $sortOptions,
        public array $users,
        public DataGridQuery $capabilityQuery,
        public DataGridPage $capabilityPage,
        public array $capabilityColumns,
        public DataGridState $capabilityState,
        public string $actionStatus = '',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
