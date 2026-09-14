<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyGroupManagementRepositoryInterface
{
    public function propertyGroups(bool $activeOnly = true): array;
    public function propertyGroup(int $id): ?array;
    public function propertyGroupProperties(int $groupId): array;
    public function updatePropertyGroup(int $groupId, array $input, array $files = []): array;
}
