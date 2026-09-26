<?php
declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class ClientCaseWorkspaceViewModel
{
    public function __construct(
        public int $id,
        public string $identity,
        public string $title,
        public string $subtitle,
        public string $statusLabel,
        public string $statusTone,
        public array $meta,
        public array $kpis,
        public array $form,
        public array $typeOptions,
        public array $statusOptions,
        public array $priorityOptions,
        public array $stageOptions,
        public array $managerOptions,
        public array $propertyTypeOptions,
        public array $locationOptions,
        public array $activityOptions,
        public array $timeline,
        public array $inboundRequests,
        public array $propertyMatches,
        public array $matchStatusOptions,
        public ?array $aiDecision,
        public array $aiActions,
        public int $requestMatchCount,
        public ?string $error = null,
    ) {}

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
