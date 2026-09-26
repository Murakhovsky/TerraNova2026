<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertyCatalogViewModel
{
    /**
     * @param array<string,mixed> $filters
     * @param list<array<string,mixed>> $types
     * @param list<array<string,mixed>> $locations
     * @param list<array<string,mixed>> $properties
     * @param array<string,mixed> $stats
     * @param array<string,mixed> $pagination
     * @param list<string> $activeFilters
     * @param array<string,mixed> $breadcrumbSchema
     * @param array<string,mixed> $itemListSchema
     */
    public function __construct(
        public array $filters,
        public array $types,
        public array $locations,
        public array $properties,
        public array $stats,
        public array $pagination,
        public array $activeFilters,
        public array $breadcrumbSchema,
        public array $itemListSchema,
        public string $dealLabel,
        public string $primaryLocation,
        public string $previousUrl,
        public string $nextUrl,
        public ?string $notice = null,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->properties === [] ? 'empty' : 'normal';
    }

    public function total(): int
    {
        return (int) ($this->pagination['total'] ?? $this->stats['total'] ?? count($this->properties));
    }
}
