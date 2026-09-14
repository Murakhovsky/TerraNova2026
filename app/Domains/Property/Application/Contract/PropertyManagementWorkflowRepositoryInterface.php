<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyManagementWorkflowRepositoryInterface
{
    public function operationalStageRules(): array;
    public function operationalStageCheck(int $propertyId): array;
    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array;
    public function readiness(int $propertyId): array;
}
