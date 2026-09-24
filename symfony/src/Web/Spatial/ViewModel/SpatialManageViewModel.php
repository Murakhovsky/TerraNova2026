<?php

declare(strict_types=1);

namespace App\Web\Spatial\ViewModel;

final readonly class SpatialManageViewModel
{
    /**
     * @param list<array<string,mixed>> $scenes
     * @param array<string,int> $stats
     * @param array<string,string> $filters
     * @param array<string,string> $statusOptions
     */
    public function __construct(
        public array $scenes,
        public array $stats,
        public array $filters,
        public array $statusOptions,
        public string $statusMessage = '',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->scenes === [] ? 'empty' : 'normal';
    }
}
