<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyGroupManagementRepositoryInterface;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;

final readonly class LegacyPropertyGroupManagementRepository implements PropertyGroupManagementRepositoryInterface
{
    public function __construct(private MysqlPropertyManagementRepository $backend) {}

    public function propertyGroups(bool $activeOnly = true): array { return $this->backend->propertyGroups($activeOnly); }
    public function propertyGroup(int $id): ?array { return $this->backend->propertyGroup($id); }
    public function propertyGroupProperties(int $groupId): array { return $this->backend->propertyGroupProperties($groupId); }
    public function updatePropertyGroup(int $groupId, array $input, array $files = []): array { return $this->backend->updatePropertyGroup($groupId, $input, $files); }
}
