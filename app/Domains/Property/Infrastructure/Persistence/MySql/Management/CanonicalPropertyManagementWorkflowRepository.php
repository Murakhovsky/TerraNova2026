<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyManagementWorkflowRepositoryInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use Throwable;

final readonly class CanonicalPropertyManagementWorkflowRepository implements PropertyManagementWorkflowRepositoryInterface
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private MysqlPropertyManagementRepository $legacyOperations,
        private string $organizationId,
    ) {}

    public function operationalStageRules(): array { return $this->legacyOperations->operationalStageRules(); }
    public function operationalStageCheck(int $propertyId): array { return $this->legacyOperations->operationalStageCheck($propertyId); }

    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array
    {
        if ($action !== 'publish') return $this->legacyOperations->quickAction($propertyId, $action, $input, $userId);
        try {
            $this->runtime->applyLegacyStatus(
                $this->organizationId,
                $propertyId,
                'published',
                isset($input['status_note']) ? (string) $input['status_note'] : null,
                $userId !== null && $userId > 0 ? 'user:' . $userId : null,
            );
            return ['ok' => true, 'message' => 'Об’єкт опубліковано через canonical Listing/Publication runtime.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося опублікувати об’єкт: ' . $e->getMessage()];
        }
    }

    public function readiness(int $propertyId): array { return $this->legacyOperations->readiness($propertyId); }
}
