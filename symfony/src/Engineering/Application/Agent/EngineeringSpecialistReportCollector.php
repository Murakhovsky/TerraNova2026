<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;

final readonly class EngineeringSpecialistReportCollector
{
    public function __construct(private EngineeringArtifactStoreInterface $artifacts) {}

    /** @return array<string,array<string,mixed>> */
    public function forFeature(string $featureId): array
    {
        $types = [
            ArtifactType::SECURITY_REVIEW_REPORT,
            ArtifactType::MIGRATION_REVIEW_REPORT,
            ArtifactType::PERFORMANCE_REVIEW_REPORT,
            ArtifactType::DEVOPS_REVIEW_REPORT,
            ArtifactType::DOCUMENTATION_REPORT,
            ArtifactType::API_REVIEW_REPORT,
        ];
        $reports = [];
        foreach ($types as $type) {
            $artifact = $this->artifacts->latest($featureId, $type);
            if ($artifact !== null) $reports[$type->value] = $artifact['content'];
        }
        return $reports;
    }
}
