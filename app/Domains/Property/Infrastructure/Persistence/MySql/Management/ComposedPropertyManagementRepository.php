<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyGroupManagementRepositoryInterface;
use Domains\Property\Application\Contract\PropertyManagementReadRepositoryInterface;
use Domains\Property\Application\Contract\PropertyManagementRepositoryInterface;
use Domains\Property\Application\Contract\PropertyManagementWorkflowRepositoryInterface;
use Domains\Property\Application\Contract\PropertyManagementWriteRepositoryInterface;

final readonly class ComposedPropertyManagementRepository implements PropertyManagementRepositoryInterface
{
    public function __construct(
        private PropertyManagementReadRepositoryInterface $read,
        private PropertyGroupManagementRepositoryInterface $groups,
        private PropertyManagementWriteRepositoryInterface $write,
        private PropertyManagementWorkflowRepositoryInterface $workflow,
    ) {}

    public function property(int $id): ?array { return $this->read->property($id); }
    public function images(int $propertyId): array { return $this->read->images($propertyId); }
    public function agents(): array { return $this->read->agents(); }
    public function propertyGroups(bool $activeOnly = true): array { return $this->groups->propertyGroups($activeOnly); }
    public function propertyGroup(int $id): ?array { return $this->groups->propertyGroup($id); }
    public function propertyGroupProperties(int $groupId): array { return $this->groups->propertyGroupProperties($groupId); }
    public function updatePropertyGroup(int $groupId, array $input, array $files = []): array { return $this->groups->updatePropertyGroup($groupId, $input, $files); }
    public function activities(int $propertyId, int $limit = 20): array { return $this->read->activities($propertyId, $limit); }
    public function inboundRequests(int $propertyId): array { return $this->read->inboundRequests($propertyId); }
    public function caseMatches(int $propertyId): array { return $this->read->caseMatches($propertyId); }
    public function adminFilters(array $query): array { return $this->read->adminFilters($query); }
    public function adminProperties(array $filters): array { return $this->read->adminProperties($filters); }
    public function listingProperties(array $filters, array $user): array { return $this->read->listingProperties($filters, $user); }
    public function adminQualityStats(array $filters): array { return $this->read->adminQualityStats($filters); }
    public function adminStats(): array { return $this->read->adminStats(); }
    public function operationalStageRules(): array { return $this->workflow->operationalStageRules(); }
    public function operationalStageCheck(int $propertyId): array { return $this->workflow->operationalStageCheck($propertyId); }
    public function createDraft(array $input, ?int $userId = null, array $files = []): array { return $this->write->createDraft($input, $userId, $files); }
    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array { return $this->write->updateStatus($propertyId, $status, $note, $userId); }
    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array { return $this->write->updateDetails($propertyId, $input, $userId); }
    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array { return $this->write->update($propertyId, $input, $files, $userId); }
    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array { return $this->write->addActivityNote($propertyId, $input, $userId); }
    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array { return $this->workflow->quickAction($propertyId, $action, $input, $userId); }
    public function readiness(int $propertyId): array { return $this->workflow->readiness($propertyId); }
}
