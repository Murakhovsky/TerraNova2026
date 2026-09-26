<?php

declare(strict_types=1);

namespace App\Web\Visualization\ViewModel;

final readonly class ArchitectureExplorerViewModel
{
    /**
     * @param array<string,array<string,mixed>> $viewDescriptions
     * @param array<string,int> $nodeTypes
     * @param list<array<string,mixed>> $domains
     * @param list<array<string,mixed>> $healthIssues
     * @param array{stage:string,exception:string,detail:string,message:string}|null $diagnostic
     */
    public function __construct(
        public array $viewDescriptions,
        public string $defaultView,
        public string $defaultViewLabel,
        public int $nodeCount,
        public int $edgeCount,
        public array $nodeTypes,
        public array $domains,
        public string $healthStatus,
        public int $healthErrors,
        public int $healthWarnings,
        public int $healthInfo,
        public array $healthIssues,
        public string $graphJson,
        public ?array $diagnostic = null,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }

    public function healthTone(): string
    {
        return match ($this->healthStatus) {
            'ok' => 'positive',
            'error' => 'danger',
            default => 'warning',
        };
    }
}
