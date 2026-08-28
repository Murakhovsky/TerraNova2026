<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyManagementInterface;
use Domains\Property\Application\Contract\PropertyManagementRepositoryInterface;
use Domains\Property\Application\Contract\PropertyNotificationInterface;
use Domains\Property\Model\PropertyWorkflowPolicy;

final readonly class PropertyManagementService implements PropertyManagementInterface
{
    public function __construct(
        private PropertyManagementRepositoryInterface $properties,
        private ?PropertyNotificationInterface $notifications = null,
        private ?PropertyWorkflowPolicy $workflow = null,
    ) {}

    public function property(int $id): ?array { return $this->properties->property($id); }
    public function images(int $propertyId): array { return $this->properties->images($propertyId); }
    public function agents(): array { return $this->properties->agents(); }
    public function propertyGroups(bool $activeOnly = true): array { return $this->properties->propertyGroups($activeOnly); }
    public function propertyGroup(int $id): ?array { return $this->properties->propertyGroup($id); }
    public function propertyGroupProperties(int $groupId): array { return $this->properties->propertyGroupProperties($groupId); }
    public function updatePropertyGroup(int $groupId, array $input, array $files = []): array
    {
        return $this->properties->updatePropertyGroup($groupId, $input, $files);
    }
    public function activities(int $propertyId, int $limit = 20): array { return $this->properties->activities($propertyId, $limit); }
    public function inboundRequests(int $propertyId): array { return $this->properties->inboundRequests($propertyId); }
    public function caseMatches(int $propertyId): array { return $this->properties->caseMatches($propertyId); }
    public function adminFilters(array $query): array { return $this->properties->adminFilters($query); }
    public function adminProperties(array $filters): array { return $this->properties->adminProperties($filters); }
    public function listingProperties(array $filters, array $user): array { return $this->properties->listingProperties($filters, $user); }
    public function adminQualityStats(array $filters): array { return $this->properties->adminQualityStats($filters); }
    public function adminStats(): array { return $this->properties->adminStats(); }
    public function operationalStageRules(): array
    {
        return ($this->workflow ?? new PropertyWorkflowPolicy())->stageRules();
    }
    public function operationalStageCheck(int $propertyId): array { return $this->properties->operationalStageCheck($propertyId); }
    public function createDraft(array $input, ?int $userId = null, array $files = []): array
    {
        return $this->properties->createDraft($input, $userId, $files);
    }
    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array
    {
        $result = $this->properties->updateStatus($propertyId, $status, $note, $userId);
        if (!empty($result['ok'])) {
            $this->notifications?->notifyPropertyStatus($propertyId, $status, $note);
        }

        return $result;
    }
    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array
    {
        return $this->properties->updateDetails($propertyId, $input, $userId);
    }
    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array
    {
        return $this->properties->update($propertyId, $input, $files, $userId);
    }
    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array
    {
        return $this->properties->addActivityNote($propertyId, $input, $userId);
    }
    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array
    {
        return $this->properties->quickAction($propertyId, $action, $input, $userId);
    }
    public function readiness(int $propertyId): array { return $this->properties->readiness($propertyId); }
}
