<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesTeamAdministrationInterface
{
    public function catalog(): array;
    public function users(string $organizationId): array;
    public function teams(string $organizationId): array;
    public function team(string $organizationId, string $teamId): ?array;
    public function createTeam(string $organizationId, array $input, string $actorId): array;
    public function updateTeam(string $organizationId, string $teamId, array $input, int $expectedVersion, string $actorId): array;
    public function setMember(string $organizationId, string $teamId, int $userId, array $input, string $actorId): array;
    public function setCapabilities(string $organizationId, int $userId, array $capabilities, string $actorId): array;
    public function revisions(string $organizationId, string $entityId, int $limit = 100): array;
}
