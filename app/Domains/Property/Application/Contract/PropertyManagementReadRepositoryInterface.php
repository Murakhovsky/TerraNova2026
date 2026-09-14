<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyManagementReadRepositoryInterface
{
    public function property(int $id): ?array;
    public function images(int $propertyId): array;
    public function agents(): array;
    public function activities(int $propertyId, int $limit = 20): array;
    public function inboundRequests(int $propertyId): array;
    public function caseMatches(int $propertyId): array;
    public function adminFilters(array $query): array;
    public function adminProperties(array $filters): array;
    public function listingProperties(array $filters, array $user): array;
    public function adminQualityStats(array $filters): array;
    public function adminStats(): array;
}
