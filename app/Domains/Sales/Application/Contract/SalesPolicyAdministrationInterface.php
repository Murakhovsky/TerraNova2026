<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesPolicyAdministrationInterface
{
    public function catalog(string $organizationId): array;
    public function actions(string $organizationId): array;
    public function create(string $organizationId, array $input, string $actorId): array;
    public function update(string $organizationId, string $policyId, array $input, int $expectedVersion, string $actorId): array;
    public function archive(string $organizationId, string $policyId, int $expectedVersion, string $actorId): array;
    public function preview(string $organizationId, array $input): array;
    public function revisions(string $organizationId, string $policyId, int $limit = 100): array;
}
