<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use InvalidArgumentException;
use RuntimeException;

final readonly class EngineeringDomainDependencyEditor
{
    /** @var list<string> */
    private const TYPES = ['REQUIRES','BLOCKS','EXTENDS','IMPLEMENTS','USES','MIGRATES','INTEGRATES_WITH'];

    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringArtifactDependencyGraph $artifactGraph,
        private FeatureDependencyGraph $graph = new FeatureDependencyGraph(),
    ) {}

    /** @param list<array<string,mixed>> $dependencies @return array<string,mixed> */
    public function replace(
        string $domainId,
        string $organizationId,
        array $dependencies,
        string $editedBy,
        string $correlationId,
    ): array {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
        if (!in_array((string) ($domain['status'] ?? ''), [
            EngineeringDomainStatus::DECOMPOSITION->value,
            EngineeringDomainStatus::ARCHITECTURE->value,
            EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value,
            EngineeringDomainStatus::BLOCKED->value,
        ], true)) {
            throw new RuntimeException('Dependency graph can be edited only before Domain feature execution starts.');
        }

        $features = $this->domains->features($domainId);
        foreach ($features as $feature) {
            if (($feature['engineering_feature_id'] ?? null) !== null) {
                throw new RuntimeException('Dependency graph is immutable after child Feature workflows have been linked.');
            }
        }

        $normalized = [];
        $seen = [];
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) throw new InvalidArgumentException('Dependency edge must be an object.');
            $featureKey = trim((string) ($dependency['feature_key'] ?? ''));
            $dependsOnKey = trim((string) ($dependency['depends_on_key'] ?? ''));
            $type = strtoupper(trim((string) ($dependency['type'] ?? 'REQUIRES')));
            if ($featureKey === '' || $dependsOnKey === '') throw new InvalidArgumentException('Dependency edge requires feature_key and depends_on_key.');
            if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Unsupported dependency type '.$type.'.');
            $signature = strtolower($featureKey).'|'.strtolower($dependsOnKey).'|'.$type;
            if (isset($seen[$signature])) continue;
            $seen[$signature] = true;
            $normalized[] = [
                'feature_key' => $featureKey,
                'depends_on_key' => $dependsOnKey,
                'type' => $type,
            ];
        }

        $this->graph->assertValid(
            array_map(static fn (array $feature): array => ['key' => (string) $feature['feature_key']], $features),
            $normalized,
        );

        $this->domains->replaceDependencies($domainId, $normalized);
        $previous = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::FEATURE_DEPENDENCY_GRAPH->value);
        $artifact = $this->domains->saveArtifact(
            $domainId,
            EngineeringDomainArtifactType::FEATURE_DEPENDENCY_GRAPH->value,
            [
                'dependencies' => $normalized,
                'parallelization_groups' => $previous['content']['parallelization_groups'] ?? [],
                'critical_path' => $previous['content']['critical_path'] ?? [],
                'edited_by' => $editedBy,
                'edited_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'source' => 'HUMAN_VISUAL_EDITOR',
            ],
            $editedBy,
        );
        $this->artifactGraph->rebuild($domainId);

        $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_DECOMPOSITION_READY->value,
            null,
            [
                'manual_dependency_edit' => true,
                'dependency_count' => count($normalized),
                'artifact_id' => $artifact['id'],
                'artifact_version' => $artifact['version'],
                'fingerprint' => $fingerprint,
            ],
            $correlationId,
            'domain-dependency-graph-edited:'.$fingerprint,
            actor: $editedBy,
            reason: 'Human edited the Domain dependency graph before child workflow execution.',
            artifactId: (string) $artifact['id'],
            result: 'UPDATED',
        );

        return [
            'domain' => $this->domains->domain($domainId),
            'dependencies' => $this->domains->dependencies($domainId),
            'artifact' => $artifact,
        ];
    }
}
