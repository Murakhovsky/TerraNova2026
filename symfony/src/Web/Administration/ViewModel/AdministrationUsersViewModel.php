<?php

declare(strict_types=1);

namespace App\Web\Administration\ViewModel;

final readonly class AdministrationUsersViewModel
{
    /**
     * @param array{q:string,role:string,status:string,sort:string} $filters
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param array<string,string> $roleLabels
     * @param array<string,string> $statusLabels
     * @param list<array<string,mixed>> $users
     */
    public function __construct(
        public array $filters,
        public array $kpis,
        public array $roleLabels,
        public array $statusLabels,
        public AdministrationUsersGridViewModel $capabilities,
        public array $users,
        public string $actionStatus = '',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) return 'error';
        return $this->users === [] ? 'empty' : 'normal';
    }
}
