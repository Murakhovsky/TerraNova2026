<?php
declare(strict_types=1);

namespace Domains\Identity\Application\Contract;

interface AdministrationServiceInterface
{
    public function metrics(): array;
    public function propertyStatus(): array;
    public function submissionStatus(): array;
    public function caseStages(): array;
    public function recentSubmissions(int $limit = 6): array;
    public function recentRequests(int $limit = 6): array;
    public function activeCases(int $limit = 6): array;
    public function attentionProperties(int $limit = 8): array;
    public function moderationProperties(int $limit = 6): array;
    public function activeProperties(int $limit = 6): array;
    public function recentManagerActivities(int $limit = 8): array;
    public function userFilters(array $query): array;
    public function users(array $filters): array;
    public function userStats(): array;
    public function createUser(array $input): array;
    public function updateUser(int $id, array $input, ?array $actor = null): array;
}
