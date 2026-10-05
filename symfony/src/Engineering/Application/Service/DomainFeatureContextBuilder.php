<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Infrastructure\Persistence\Doctrine\DoctrineDomainDevelopmentStore;

final readonly class DomainFeatureContextBuilder
{
    public function __construct(private DoctrineDomainDevelopmentStore $domains) {}

    /** @param array<string,mixed> $domain
     *  @param array<string,mixed> $feature
     *  @param list<string> $dependencyIds
     *  @return array<string,mixed>
     */
    public function build(array $domain, array $feature, array $dependencyIds): array
    {
        $features = [];
        foreach ($this->domains->features((string) $domain['id']) as $candidate) {
            if (in_array((string) $candidate['id'], $dependencyIds, true)) {
                $features[] = [
                    'id' => $candidate['id'],
                    'feature_key' => $candidate['feature_key'],
                    'title' => $candidate['title'],
                    'status' => $candidate['status'],
                    'engineering_feature_id' => $candidate['engineering_feature_id'],
                    'architecture_version' => $candidate['architecture_version'],
                ];
            }
        }

        return [
            'domain' => [
                'id' => $domain['id'],
                'key' => $domain['domain_key'],
                'name' => $domain['name'],
                'version' => $domain['version'],
                'architecture_version' => $domain['architecture_version'],
                'target_repository' => $domain['target_repository'],
                'target_branch' => $domain['target_branch'],
            ],
            'domain_architecture' => $domain['architecture_json'],
            'architecture_constitution' => $domain['constitution_json'],
            'domain_acceptance_criteria' => $domain['domain_acceptance_criteria'],
            'dependency_context' => $features,
            'contract_registry' => $this->domains->contracts((string) $domain['id']),
            'path_ownership' => [
                'owned_paths' => $feature['owned_paths'],
                'shared_paths' => $feature['shared_paths'],
                'forbidden_paths' => $feature['forbidden_paths'],
            ],
            'hard_rules' => [
                'Do not change Domain Architecture or Architecture Constitution from a feature workflow.',
                'Do not change public contracts silently.',
                'Do not modify forbidden paths.',
                'Treat dependency contracts and completed upstream features as authoritative.',
                'If implementation conflicts with Domain Architecture, return ARCHITECTURE_REVIEW_REQUIRED.',
            ],
        ];
    }
}
