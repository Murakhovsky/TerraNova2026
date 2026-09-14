<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyManagementWorkflowRepositoryInterface;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;

final readonly class LegacyPropertyManagementWorkflowRepository implements PropertyManagementWorkflowRepositoryInterface
{
    public function __construct(private MysqlPropertyManagementRepository $backend) {}

    public function operationalStageRules(): array { return $this->backend->operationalStageRules(); }
    public function operationalStageCheck(int $propertyId): array { return $this->backend->operationalStageCheck($propertyId); }
    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array { return $this->backend->quickAction($propertyId, $action, $input, $userId); }
    public function readiness(int $propertyId): array { return $this->backend->readiness($propertyId); }
}
