<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesAgentAdministrationInterface
{
    public function catalog(): array;
    public function agents(string $organizationId): array;
    public function agent(string $organizationId, string $agentName): ?array;
    public function update(string $organizationId, string $agentName, array $input, int $expectedVersion, string $actorId): array;
    public function test(string $organizationId, string $agentName, array $input, string $actorId): array;
    public function revisions(string $organizationId, string $agentName, int $limit = 100): array;
}
