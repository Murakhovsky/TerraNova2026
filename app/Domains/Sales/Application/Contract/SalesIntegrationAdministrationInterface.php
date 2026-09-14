<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesIntegrationAdministrationInterface
{
    public function catalog(): array;
    public function integrations(string $organizationId): array;
    public function routingOptions(string $organizationId): array;
    public function integration(string $organizationId, int $integrationId): ?array;
    public function create(string $organizationId, array $input, string $actorId): array;
    public function update(string $organizationId, int $integrationId, array $input, int $expectedVersion, string $actorId): array;
    public function testConnection(string $organizationId, int $integrationId): array;
    public function saveRoute(string $organizationId, int $integrationId, array $input, string $actorId): array;
    public function revisions(string $organizationId, int $integrationId, int $limit = 100): array;
}
