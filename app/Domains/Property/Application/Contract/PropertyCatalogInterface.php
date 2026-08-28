<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyCatalogInterface
{
    public function filtersFromQuery(array $query): array;
    public function featuredProperties(int $limit = 3): array;
    public function catalogProperties(array $filters): array;
    public function catalogCount(array $filters): int;
    public function catalogStats(array $filters): array;
    public function catalogPagination(array $filters, int $total): array;
    public function propertyBySlug(string $slug): ?array;
    public function recordPropertyView(int $propertyId, array $context = []): void;
    public function groupedProperties(array $property, int $limit = 8): array;
    public function propertyGroupBySlug(string $slug): ?array;
    public function propertyGroupPresentationProperties(int $groupId, int $limit = 24): array;
    public function propertyImages(int $propertyId): array;
    public function propertyFeatures(int $propertyId): array;
    public function relatedProperties(array $property, int $limit = 3): array;
    public function propertyTypes(): array;
    public function locations(): array;
    public function sitemapProperties(): array;
    public function seoLandingPairs(): array;
}
