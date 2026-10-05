<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use RuntimeException;

final readonly class EngineeringDomainDocumentationService
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringArtifactDependencyGraph $artifactGraph,
        private EngineeringDomainDocumentationTranslationService $translator,
        private string $canonicalLocale = 'uk',
        private string $translationLocales = 'en',
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function generate(string $domainId, ?string $correlationId = null): array
    {
        $domain = $this->domains->domain($domainId);
        $spec = $this->required($domainId, EngineeringDomainArtifactType::DOMAIN_SPECIFICATION);
        $architecture = $this->required($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE);
        $constitution = $this->required($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION);
        $migration = $this->required($domainId, EngineeringDomainArtifactType::MIGRATION_PLAN);
        $flags = $this->required($domainId, EngineeringDomainArtifactType::DOMAIN_FEATURE_FLAGS);
        $capabilities = $this->domains->capabilities($domainId);
        $features = $this->domains->features($domainId);
        $contracts = $this->domains->contracts($domainId);
        $events = $this->domains->events($domainId);

        $public = [
            'locale' => $this->canonicalLocale,
            'audience' => 'PUBLIC_BUSINESS',
            'domain' => ['key' => $domain['domain_key'], 'name' => $domain['name'], 'version' => (int) $domain['version']],
            'what_it_is' => $this->pick($spec['content'], ['purpose','description','business_context']),
            'why_it_exists' => $this->pick($spec['content'], ['business_goal','goals','problem','business_context']),
            'what_it_can_do' => array_map(static fn (array $capability): array => [
                'key' => $capability['capability_key'] ?? null,
                'name' => $capability['name'] ?? null,
                'description' => $capability['description'] ?? null,
                'required' => (bool) ($capability['required'] ?? false),
                'status' => $capability['status'] ?? null,
            ], $capabilities),
        ];

        $integrator = [
            'locale' => $this->canonicalLocale,
            'audience' => 'INTEGRATOR',
            'domain' => ['key' => $domain['domain_key'], 'name' => $domain['name'], 'version' => (int) $domain['version']],
            'configuration' => [
                'feature_flags' => $flags['content'],
                'concurrency' => [
                    'features' => $domain['max_parallel_features'],
                    'developers' => $domain['max_parallel_developers'],
                    'reviews' => $domain['max_parallel_reviews'],
                    'qa' => $domain['max_parallel_qa'],
                ],
                'budgets' => [
                    'feature_retries' => $domain['max_feature_retries'],
                    'integration_cycles' => $domain['max_domain_integration_cycles'],
                    'context' => $domain['context_budget'],
                    'tokens' => $domain['token_budget'],
                    'cost' => $domain['cost_budget'],
                ],
            ],
            'permissions' => array_values(array_filter(
                $contracts,
                static fn (array $contract): bool => ($contract['type'] ?? null) === 'PERMISSION_CONTRACT',
            )),
            'workflows' => [
                'features' => array_map(static fn (array $feature): array => [
                    'key' => $feature['feature_key'] ?? null,
                    'capability' => $feature['capability_key'] ?? null,
                    'kind' => $feature['kind'] ?? null,
                    'required' => (bool) ($feature['required'] ?? false),
                    'status' => $feature['status'] ?? null,
                ], $features),
                'dependencies' => $this->domains->dependencies($domainId),
            ],
            'integration' => [
                'contracts' => $contracts,
                'events' => $events,
                'target_repository' => $domain['target_repository'],
                'integration_branch' => $domain['target_branch'],
            ],
            'deployment' => [
                'migration_plan' => $migration['content'],
                'release_strategy' => $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::INTEGRATION_STRATEGY->value)['content'] ?? [],
            ],
        ];

        $developer = [
            'locale' => $this->canonicalLocale,
            'audience' => 'DEVELOPER',
            'domain' => ['key' => $domain['domain_key'], 'name' => $domain['name'], 'version' => (int) $domain['version']],
            'architecture' => $architecture['content'],
            'architecture_constitution' => $constitution['content'],
            'interfaces' => $contracts,
            'events' => $events,
            'database' => [
                'migration_plan' => $migration['content'],
                'ownership' => $this->pick($architecture['content'], ['database_ownership','persistence','database']),
            ],
            'extension_points' => $this->pick($architecture['content'], ['extension_points','integration_boundaries','interfaces','ports']),
            'repository_context_index' => $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::REPOSITORY_CONTEXT_INDEX->value)['content']['counts'] ?? [],
        ];

        $artifacts = [
            'public' => $this->domains->saveArtifact(
                $domainId,
                EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_PUBLIC->value,
                $public,
                'DOMAIN_DOCUMENTATION_RUNTIME',
            ),
            'integrator' => $this->domains->saveArtifact(
                $domainId,
                EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_INTEGRATOR->value,
                $integrator,
                'DOMAIN_DOCUMENTATION_RUNTIME',
            ),
            'developer' => $this->domains->saveArtifact(
                $domainId,
                EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_DEVELOPER->value,
                $developer,
                'DOMAIN_DOCUMENTATION_RUNTIME',
            ),
        ];

        $contentRefs = array_map(static fn (array $artifact): array => [
            'artifact_id' => $artifact['id'],
            'type' => $artifact['type'],
            'version' => $artifact['version'],
            'hash' => $artifact['content_hash'],
        ], $artifacts);

        $translations = [
            'canonical_locale' => $this->canonicalLocale,
            'translation_layer' => 'SEPARATE_ARTIFACT',
            'translations' => [],
            'translation_failures' => [],
            'locale_contract' => [
                'key_format' => '<locale>',
                'content_refs' => $contentRefs,
                'future_locales_supported' => true,
            ],
        ];

        $sourceDocuments = [
            [
                'audience' => 'PUBLIC_BUSINESS',
                'title' => (string) $domain['name'].' — Business',
                'content' => $public,
            ],
            [
                'audience' => 'INTEGRATOR',
                'title' => (string) $domain['name'].' — Integrator',
                'content' => $integrator,
            ],
            [
                'audience' => 'DEVELOPER',
                'title' => (string) $domain['name'].' — Developer',
                'content' => $developer,
            ],
        ];

        foreach ($this->targetLocales() as $locale) {
            if ($locale === $this->canonicalLocale) continue;
            try {
                $translated = $this->translator->translate(
                    $domainId,
                    (string) $domain['organization_id'],
                    $this->canonicalLocale,
                    $locale,
                    $sourceDocuments,
                    mb_substr(($correlationId ?? 'domain-documentation').':'.$locale, 0, 128),
                );
                if (($translated['status'] ?? null) !== 'TRANSLATED') {
                    $translations['translation_failures'][$locale] = [
                        'status' => $translated['status'] ?? 'FAILED',
                        'notes' => $translated['notes'] ?? [],
                    ];
                    continue;
                }
                if (strtolower(trim((string) ($translated['target_locale'] ?? ''))) !== $locale) {
                    throw new RuntimeException('Documentation agent returned mismatched target locale.');
                }

                $byAudience = [];
                foreach (is_array($translated['documents'] ?? null) ? $translated['documents'] : [] as $document) {
                    if (!is_array($document)) continue;
                    $audience = strtoupper(trim((string) ($document['audience'] ?? '')));
                    if ($audience !== '') $byAudience[$audience] = $document;
                }
                foreach (['PUBLIC_BUSINESS','INTEGRATOR','DEVELOPER'] as $audience) {
                    if (!isset($byAudience[$audience])) {
                        throw new RuntimeException('Documentation translation missing audience '.$audience.'.');
                    }
                }

                $translations['translations'][$locale] = [
                    'status' => 'TRANSLATED',
                    'documents' => [
                        'public' => $byAudience['PUBLIC_BUSINESS'],
                        'integrator' => $byAudience['INTEGRATOR'],
                        'developer' => $byAudience['DEVELOPER'],
                    ],
                    'source_refs' => $contentRefs,
                    'notes' => $translated['notes'] ?? [],
                ];
            } catch (\Throwable $error) {
                $translations['translation_failures'][$locale] = [
                    'status' => 'FAILED',
                    'error' => $error->getMessage(),
                ];
            }
        }

        $artifacts['translations'] = $this->domains->saveArtifact(
            $domainId,
            EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS->value,
            $translations,
            'DOMAIN_DOCUMENTATION_RUNTIME',
        );

        $this->artifactGraph->rebuild($domainId);
        return $artifacts;
    }

    /** @return list<string> */
    private function targetLocales(): array
    {
        $locales = [];
        foreach (preg_split('/[,;\s]+/', strtolower(trim($this->translationLocales))) ?: [] as $locale) {
            $locale = trim($locale);
            if ($locale === '' || !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $locale)) continue;
            $locales[$locale] = true;
        }
        return array_keys($locales);
    }

    /** @return array<string,mixed> */
    private function required(string $domainId, EngineeringDomainArtifactType $type): array
    {
        $artifact = $this->domains->latestArtifact($domainId, $type->value);
        if ($artifact === null) throw new RuntimeException('Domain documentation requires '.$type->value.'.');
        return $artifact;
    }

    /** @param array<string,mixed> $source @param list<string> $keys */
    private function pick(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) continue;
            $value = $source[$key];
            if ($value !== null && $value !== '' && $value !== []) return $value;
        }
        return null;
    }
}
