<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;
use RuntimeException;

final readonly class EngineeringFeatureManagementService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
    ) {}

    /** @return array<string,mixed> */
    public function update(
        string $featureId,
        string $organizationId,
        string $title,
        string $description,
        string $priority,
    ): array {
        $feature = $this->ownedFeature($featureId, $organizationId);
        $this->assertDraft($featureId);

        $title = trim($title);
        $description = trim($description);
        $priority = strtoupper(trim($priority));

        if ($title === '') throw new RuntimeException('Назва engineering feature обов’язкова.');
        if ($description === '') throw new RuntimeException('Description engineering feature обов’язковий.');
        if (!in_array($priority, ['P0','P1','P2','P3'], true)) {
            throw new RuntimeException('Priority має бути P0, P1, P2 або P3.');
        }

        $this->features->updateRequest(
            $featureId,
            mb_substr($title, 0, 255),
            $description,
            $priority,
        );

        return $this->features->view($featureId);
    }

    public function delete(string $featureId, string $organizationId): void
    {
        $this->ownedFeature($featureId, $organizationId);
        $this->assertDeletable($featureId);
        $this->features->delete($featureId);
    }

    /** @return array<string,mixed> */
    private function ownedFeature(string $featureId, string $organizationId): array
    {
        $feature = $this->features->view($featureId);
        if (($feature['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering feature does not belong to the current organization.');
        }
        return $feature;
    }

    private function assertDraft(string $featureId): void
    {
        if ($this->workflows->latestIdForFeature($featureId) !== null) {
            throw new RuntimeException(
                'Started Engineering workflow is immutable. Cancel it if needed; create a new feature for changed requirements.'
            );
        }
    }

    private function assertDeletable(string $featureId): void
    {
        $workflowId = $this->workflows->latestIdForFeature($featureId);
        if ($workflowId === null) {
            return;
        }

        $state = $this->workflows->get($workflowId)->currentState()->value;
        if (!in_array($state, ['CANCELLED', 'FAILED'], true)) {
            throw new RuntimeException(
                'Only draft, CANCELLED or FAILED Engineering workflows can be deleted.'
            );
        }

        $development = $this->artifacts->latest($featureId, ArtifactType::DEVELOPMENT_RESULT);
        if (is_array($development)) {
            $content = is_array($development['content'] ?? null) ? $development['content'] : [];
            if (($content['pull_request'] ?? null) !== null || trim((string) ($content['pull_request_url'] ?? '')) !== '') {
                throw new RuntimeException(
                    'Engineering workflow with a pull request cannot be hard-deleted; keep it for audit history.'
                );
            }
        }
    }
}
