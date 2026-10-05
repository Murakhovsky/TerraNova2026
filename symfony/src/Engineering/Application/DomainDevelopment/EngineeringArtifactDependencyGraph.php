<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;

final readonly class EngineeringArtifactDependencyGraph
{
    public function __construct(private EngineeringDomainStoreInterface $domains) {}

    /** @return list<array{source_artifact_id:string,target_artifact_id:string,relationship:string}> */
    public function rebuild(string $domainId): array
    {
        $artifacts = $this->domains->artifacts($domainId);
        $byType = [];
        foreach ($artifacts as $artifact) {
            if (!is_array($artifact)) continue;
            $type = (string) ($artifact['type'] ?? '');
            if ($type !== '') $byType[$type] = $artifact;
        }

        $definitions = [
            [EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, EngineeringDomainArtifactType::DOMAIN_ACCEPTANCE_CRITERIA->value, 'DEFINES'],
            [EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, EngineeringDomainArtifactType::CAPABILITY_SPECIFICATION->value, 'DECOMPOSES_TO'],
            [EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value, 'VERIFIED_BY_PLAN'],
            [EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, 'CONSTRAINS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION->value, 'GOVERNED_BY'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_DECOMPOSITION->value, 'DECOMPOSES_TO'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::FEATURE_DEPENDENCY_GRAPH->value, 'DEFINES_GRAPH'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::CONTRACT_REGISTRY->value, 'DEFINES_CONTRACTS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_EVENT_REGISTRY->value, 'DEFINES_EVENTS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::FEATURE_CONTEXT_PACK->value, 'CONTEXT_FOR'],
            [EngineeringDomainArtifactType::REPOSITORY_CONTEXT_INDEX->value, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, 'INFORMS'],
            [EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_PUBLIC->value, 'DOCUMENTS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_INTEGRATOR->value, 'DOCUMENTS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_DEVELOPER->value, 'DOCUMENTS'],
            [EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_PUBLIC->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS->value, 'TRANSLATION_SOURCE'],
            [EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_INTEGRATOR->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS->value, 'TRANSLATION_SOURCE'],
            [EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_DEVELOPER->value, EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS->value, 'TRANSLATION_SOURCE'],
            [EngineeringDomainArtifactType::MIGRATION_PLAN->value, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, 'VERIFIED_BY'],
            [EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, 'EXECUTED_AS'],
            [EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, 'VERIFIED_BY'],
            [EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, EngineeringDomainArtifactType::DOMAIN_INTEGRATION_RELEASE_REPORT->value, 'RELEASE_INPUT'],
            [EngineeringDomainArtifactType::DOMAIN_INTEGRATION_RELEASE_REPORT->value, EngineeringDomainArtifactType::DOMAIN_RELEASE_MANIFEST->value, 'RELEASE_INPUT'],
            [EngineeringDomainArtifactType::DOMAIN_FEATURE_FLAGS->value, EngineeringDomainArtifactType::DOMAIN_RELEASE_MANIFEST->value, 'RELEASE_CONFIG'],
        ];

        $edges = [];
        foreach ($definitions as [$sourceType, $targetType, $relationship]) {
            $source = $byType[$sourceType] ?? null;
            $target = $byType[$targetType] ?? null;
            if (!is_array($source) || !is_array($target)) continue;
            $edges[] = [
                'source_artifact_id' => (string) $source['id'],
                'target_artifact_id' => (string) $target['id'],
                'relationship' => $relationship,
            ];
        }

        foreach ($artifacts as $artifact) {
            if (!is_array($artifact)) continue;
            $supersedes = trim((string) ($artifact['supersedes_artifact_id'] ?? ''));
            if ($supersedes === '') continue;
            $edges[] = [
                'source_artifact_id' => (string) $artifact['id'],
                'target_artifact_id' => $supersedes,
                'relationship' => 'SUPERSEDES',
            ];
        }

        $this->domains->replaceArtifactDependencies($domainId, $edges);
        return $edges;
    }
}
