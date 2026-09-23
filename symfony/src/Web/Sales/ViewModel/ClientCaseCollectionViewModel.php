<?php
declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class ClientCaseCollectionViewModel
{
    public function __construct(
        public array $filters,
        public array $stageTabs,
        public array $stageOptions,
        public array $managerOptions,
        public array $propertyTypeOptions,
        public array $locationOptions,
        public array $openCaseOptions,
        public array $typeOptions,
        public array $statusOptions,
        public array $priorityOptions,
        public array $sortOptions,
        public array $cases,
        public array $funnelStages,
        public array $unlinkedRequests,
        public int $total,
        public ?string $error = null,
    ) {}

    public function state(): string
    {
        if ($this->error !== null) return 'error';
        return $this->cases === [] ? 'empty' : 'normal';
    }
}
