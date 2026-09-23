<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class ClientCaseInboxViewModel
{
    /**
     * @param array<string,string|int> $filters
     * @param list<array{label:string,value:string,hint:string,href:string,tone:string}> $metrics
     * @param list<array{key:string,label:string,href:string,active:bool}> $statusTabs
     * @param list<array<string,mixed>> $requests
     * @param array<string,string> $statusOptions
     * @param array<string,string> $intentOptions
     * @param array<string,string> $activityOptions
     * @param array<string,string> $priorityOptions
     * @param array<string,string> $caseOptions
     * @param array<string,string> $sortOptions
     * @param list<array{id:int,label:string}> $managerOptions
     * @param list<array{id:int,label:string}> $openCaseOptions
     */
    public function __construct(
        public array $filters,
        public array $metrics,
        public array $statusTabs,
        public array $requests,
        public array $statusOptions,
        public array $intentOptions,
        public array $activityOptions,
        public array $priorityOptions,
        public array $caseOptions,
        public array $sortOptions,
        public array $managerOptions,
        public array $openCaseOptions,
        public int $newCount,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->requests === [] ? 'empty' : 'normal';
    }
}
