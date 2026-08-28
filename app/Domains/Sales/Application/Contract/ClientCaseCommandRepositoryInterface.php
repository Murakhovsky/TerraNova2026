<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface ClientCaseCommandRepositoryInterface
{
    public function activeManagerId(string $organizationId, mixed $value): ?int;
    public function activePropertyTypeId(mixed $value): ?int;
    public function activeLocationId(mixed $value): ?int;
    public function property(int $propertyId, bool $activeOnly = false): ?array;
    public function propertyMatch(string $organizationId, int $matchId): ?array;
    public function inboundRequest(string $organizationId, int $requestId): ?array;
    public function findPerson(string $organizationId, ?string $email, ?string $phone): ?array;
    public function createPerson(string $organizationId, array $person): int;
    public function refreshPerson(string $organizationId, int $personId, array $person): bool;
    public function updatePerson(string $organizationId, int $personId, array $person): bool;
    public function createCase(string $organizationId, int $personId, array $case): int;
    public function updateCase(string $organizationId, int $caseId, array $case): bool;
    public function quickUpdate(string $organizationId, int $caseId, array $changes): bool;
    public function addActivity(string $organizationId, int $caseId, int $personId, ?int $userId, array $activity): int;
    public function clearNextContact(string $organizationId, int $caseId): void;
    public function updateInboundRequest(string $organizationId, int $requestId, array $changes): bool;
    public function addLeadActivity(string $organizationId, int $requestId, ?int $userId, array $activity): int;
    public function syncCaseFromLead(string $organizationId, int $caseId, array $changes): bool;
    public function attachInboundRequest(string $organizationId, int $caseId, int $personId, int $requestId, ?int $userId): bool;
    public function registerInboundRequest(string $organizationId, int $caseId, int $requestId): bool;
    public function upsertPropertyMatch(string $organizationId, int $caseId, int $propertyId, array $match): bool;
    public function updatePropertyMatch(string $organizationId, int $matchId, array $match): bool;
}
