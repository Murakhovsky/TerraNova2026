<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;

final readonly class EngineeringDomainContextBuilder
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainContextCompressor $compressor,
    ) {}

    /** @return array<string,mixed> */
    public function forFeature(string $domainId, string $featureKey): array
    {
        $domain = $this->domains->domain($domainId);
        $feature = $this->domains->feature($domainId, $featureKey);
        $dependencies = array_values(array_filter(
            $this->domains->dependencies($domainId),
            static fn (array $dependency): bool => ($dependency['feature_key'] ?? null) === $featureKey,
        ));

        $dependencyFeatures = [];
        foreach ($dependencies as $dependency) {
            $dependencyFeatures[] = $this->domains->feature($domainId, (string) $dependency['depends_on_key']);
        }

        $architecture = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value);
        $constitution = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION->value);
        $specification = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value);
        $qaPlan = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value);
        $repositoryIndex = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::REPOSITORY_CONTEXT_INDEX->value);

        $specContext = $specification !== null
            ? $this->compressor->artifact($specification, ['purpose','business_context','scope','actors','capabilities','constraints'])
            : null;
        $architectureContext = $architecture !== null
            ? $this->compressor->artifact($architecture, ['bounded_context','module_structure','aggregates','services','persistence','security','feature_flags'])
            : null;
        $constitutionContext = $constitution !== null
            ? $this->compressor->artifact($constitution, ['rules','forbidden_dependencies','shared_kernel_rules','database_rules'])
            : null;
        $qaContext = $qaPlan !== null
            ? $this->compressor->artifact($qaPlan, ['release_blocking_checks','cross_feature_workflows','regression'])
            : null;
        $repositoryContext = $repositoryIndex !== null
            ? $this->compressor->repositoryIndex(is_array($repositoryIndex['content'] ?? null) ? $repositoryIndex['content'] : [])
            : null;

        return [
            'domain_id' => $domainId,
            'domain_key' => $domain['domain_key'],
            'domain_name' => $domain['name'],
            'domain_version' => (int) $domain['version'],
            'target_repository' => $domain['target_repository'],
            'target_branch' => $domain['target_branch'],
            'domain_specification' => $specContext,
            'domain_architecture' => $architectureContext,
            'architecture_version' => (int) ($architecture['version'] ?? 0),
            'architecture_hash' => $architecture['content_hash'] ?? null,
            'architecture_constitution' => $constitutionContext,
            'domain_qa_plan' => $qaContext,
            'repository_context_index' => $repositoryContext,
            'artifact_refs' => [
                'domain_specification' => $specContext['artifact_ref'] ?? null,
                'domain_architecture' => $architectureContext['artifact_ref'] ?? null,
                'architecture_constitution' => $constitutionContext['artifact_ref'] ?? null,
                'domain_qa_plan' => $qaContext['artifact_ref'] ?? null,
            ],
            'existing_code_awareness' => [
                'reuse_before_create' => true,
                'check_equivalent_class' => true,
                'check_shared_kernel_primitive' => true,
                'check_cross_domain_contract' => true,
            ],
            'feature' => $feature,
            'dependencies' => $dependencies,
            'dependency_features' => $dependencyFeatures,
            'contracts' => $this->domains->contracts($domainId),
            'events' => $this->domains->events($domainId),
            'path_policy' => [
                'owned_paths' => $feature['owned_paths'],
                'shared_paths' => $feature['shared_paths'],
                'forbidden_paths' => $feature['forbidden_paths'],
            ],
        ];
    }

    /** @return array<string,string> */
    public function contractSnapshot(string $domainId, string $featureKey): array
    {
        $feature = $this->domains->feature($domainId, $featureKey);
        $needed = array_fill_keys(array_merge($feature['contracts_consumed'], $feature['contracts_produced']), true);
        $snapshot = [];
        foreach ($this->domains->contracts($domainId) as $contract) {
            if (!isset($needed[$contract['contract_key']])) continue;
            $snapshot[(string) $contract['contract_key']] = (string) $contract['version'];
        }
        ksort($snapshot);
        return $snapshot;
    }
}
