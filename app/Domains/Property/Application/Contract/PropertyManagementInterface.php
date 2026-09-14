<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyManagementInterface
{
    public function property(int $id): ?array;
    public function images(int $propertyId): array;
    public function agents(): array;
    public function propertyGroups(bool $activeOnly = true): array;
    public function propertyGroup(int $id): ?array;
    public function propertyGroupProperties(int $groupId): array;
    public function updatePropertyGroup(int $groupId, array $input, array $files = []): array;
    public function activities(int $propertyId, int $limit = 20): array;
    public function inboundRequests(int $propertyId): array;
    public function caseMatches(int $propertyId): array;
    public function adminFilters(array $query): array;
    public function adminProperties(array $filters): array;
    public function listingProperties(array $filters, array $user): array;
    public function adminQualityStats(array $filters): array;
    public function adminStats(): array;
    public function operationalStageRules(): array;
    public function operationalStageCheck(int $propertyId): array;
    public function createDraft(array $input, ?int $userId = null, array $files = []): array;
    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array;
    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array;
    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array;
    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array;
    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array;
    public function readiness(int $propertyId): array;
}
