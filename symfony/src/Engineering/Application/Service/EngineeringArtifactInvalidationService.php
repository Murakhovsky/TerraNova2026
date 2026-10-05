<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;

final readonly class EngineeringArtifactInvalidationService
{
    public function __construct(private EngineeringArtifactStoreInterface $artifacts) {}

    public function afterProductRevision(string $featureId): int
    {
        return $this->artifacts->invalidate($featureId, [
            ArtifactType::TEST_PLAN,
            ArtifactType::ARCHITECTURE_DECISION,
            ArtifactType::IMPLEMENTATION_PLAN,
            ArtifactType::DEVELOPER_HANDOFF,
            ArtifactType::ARCHITECTURE_DOCUMENTATION,
            ArtifactType::DEVELOPMENT_RESULT,
            ArtifactType::SECURITY_REVIEW_REPORT,
            ArtifactType::MIGRATION_REVIEW_REPORT,
            ArtifactType::PERFORMANCE_REVIEW_REPORT,
            ArtifactType::DEVOPS_REVIEW_REPORT,
            ArtifactType::DOCUMENTATION_REPORT,
            ArtifactType::API_REVIEW_REPORT,
            ArtifactType::REVIEW_REPORT,
            ArtifactType::QA_REPORT,
            ArtifactType::FINAL_REPORT,
        ]);
    }

    public function afterArchitectureRevision(string $featureId): int
    {
        return $this->artifacts->invalidate($featureId, [
            ArtifactType::DEVELOPMENT_RESULT,
            ArtifactType::SECURITY_REVIEW_REPORT,
            ArtifactType::MIGRATION_REVIEW_REPORT,
            ArtifactType::PERFORMANCE_REVIEW_REPORT,
            ArtifactType::DEVOPS_REVIEW_REPORT,
            ArtifactType::DOCUMENTATION_REPORT,
            ArtifactType::API_REVIEW_REPORT,
            ArtifactType::REVIEW_REPORT,
            ArtifactType::QA_REPORT,
            ArtifactType::FINAL_REPORT,
        ]);
    }

    public function afterImplementationRevision(string $featureId): int
    {
        return $this->artifacts->invalidate($featureId, [
            ArtifactType::SECURITY_REVIEW_REPORT,
            ArtifactType::MIGRATION_REVIEW_REPORT,
            ArtifactType::PERFORMANCE_REVIEW_REPORT,
            ArtifactType::DEVOPS_REVIEW_REPORT,
            ArtifactType::DOCUMENTATION_REPORT,
            ArtifactType::API_REVIEW_REPORT,
            ArtifactType::REVIEW_REPORT,
            ArtifactType::QA_REPORT,
            ArtifactType::FINAL_REPORT,
        ]);
    }
}
