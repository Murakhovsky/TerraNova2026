<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Policy\EngineeringPolicyEngine;
use App\Engineering\Application\Policy\EngineeringSharedKernelRegistry;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use RuntimeException;

final readonly class EngineeringDomainPlanner
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainAgentService $agents,
        private EngineeringDomainContextBuilder $context,
        private EngineeringRepositoryContextIndex $repositoryIndex,
        private EngineeringDomainContextCompressor $compressor,
        private EngineeringArtifactDependencyGraph $artifactGraph,
        private EngineeringDomainHumanGateService $humanGates,
        private EngineeringDomainAnalyticsService $analytics,
        private EngineeringSharedKernelRegistry $sharedKernel = new EngineeringSharedKernelRegistry(),
        private EngineeringPolicyEngine $policy = new EngineeringPolicyEngine(),
        private FeatureDependencyGraph $graph = new FeatureDependencyGraph(),
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $domainId, string $organizationId, string $correlationId): array
    {
        $domain = $this->domains->domain($domainId);
        $this->assertTenant($domain, $organizationId);
        if (!in_array($domain['status'], [
            EngineeringDomainStatus::DRAFT->value,
            EngineeringDomainStatus::ANALYSIS->value,
            EngineeringDomainStatus::DECOMPOSITION->value,
            EngineeringDomainStatus::ARCHITECTURE->value,
            EngineeringDomainStatus::BLOCKED->value,
            EngineeringDomainStatus::FAILED->value,
        ], true)) {
            throw new RuntimeException('Domain planning can start/resume only from planning states; current status is '.$domain['status'].'.');
        }

        $resumeStatus = (string) $domain['status'];
        $resumeFromSpecification = in_array($resumeStatus, [
            EngineeringDomainStatus::DECOMPOSITION->value,
            EngineeringDomainStatus::ARCHITECTURE->value,
        ], true);
        $resumeFromQaPlan = $resumeStatus === EngineeringDomainStatus::ARCHITECTURE->value;

        if ($resumeFromSpecification) {
            $specArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_SPECIFICATION);
            $domainAcArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ACCEPTANCE_CRITERIA);
            $capabilityArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::CAPABILITY_MAP);
            $domainSpec = is_array($specArtifact['content'] ?? null) ? $specArtifact['content'] : [];
            $domainAc = is_array($domainAcArtifact['content']['criteria'] ?? null) ? $domainAcArtifact['content']['criteria'] : [];
            $capabilities = is_array($capabilityArtifact['content']['capabilities'] ?? null) ? $capabilityArtifact['content']['capabilities'] : [];
            if ($domainSpec === [] || $domainAc === [] || $capabilities === []) {
                throw new RuntimeException('Persisted Domain planning state is missing canonical specification artifacts.');
            }
        } else {
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::ANALYSIS->value);
            $manager = $this->agents->run(
                $domainId,
                $organizationId,
                AgentRole::ENGINEERING_MANAGER,
                'Coordinate preliminary Domain analysis from the Master Specification, identify ambiguity, scope and decomposition signals for Product / Requirements.',
                [
                    'domain_id' => $domainId,
                    'domain_key' => $domain['domain_key'],
                    'domain_name' => $domain['name'],
                    'master_specification' => $domain['master_specification'],
                    'target_repository' => $domain['target_repository'],
                    'target_branch' => $domain['target_branch'],
                    'human_decision_history' => $this->domains->humanDecisionHistory($domainId),
                ],
                $correlationId.':manager',
            );
    
            $managerStatus = (string) ($manager['status'] ?? '');
            if ($managerStatus !== 'SPECIFICATION_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_MANAGER_RESULT', $manager, AgentRole::ENGINEERING_MANAGER->value);
                if ($managerStatus === 'HUMAN_DECISION_REQUIRED') {
                    $this->humanGates->request(
                        $domainId,
                        $organizationId,
                        'DOMAIN_SPECIFICATION',
                        EngineeringDomainStatus::ANALYSIS,
                        $this->firstQuestion($manager, 'Domain Manager requires a human product decision.'),
                        'Domain Manager identified ambiguity that can materially change Domain scope or semantics.',
                        ['agent_output' => $manager],
                        AgentRole::ENGINEERING_MANAGER->value,
                    );
                } else {
                    $this->domains->updateStatus(
                        $domainId,
                        $managerStatus === 'FAILED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                        'Domain Manager returned '.$managerStatus.'.',
                    );
                }
                return $this->view($domainId);
            }
    
            $this->domains->saveArtifact($domainId, 'DOMAIN_MANAGER_ANALYSIS', $manager, AgentRole::ENGINEERING_MANAGER->value);
    
            $requirements = $this->agents->run(
                $domainId,
                $organizationId,
                AgentRole::PRODUCT_REQUIREMENTS,
                'Produce the authoritative Domain Specification, Domain Acceptance Criteria and capability map from the Master Specification and Manager analysis.',
                [
                    'domain_id' => $domainId,
                    'domain_key' => $domain['domain_key'],
                    'domain_name' => $domain['name'],
                    'master_specification' => $domain['master_specification'],
                    'manager_analysis' => $manager,
                    'human_decision_history' => $this->domains->humanDecisionHistory($domainId),
                    'target_repository' => $domain['target_repository'],
                    'target_branch' => $domain['target_branch'],
                ],
                $correlationId.':product-requirements',
            );
    
            $requirementsStatus = (string) ($requirements['status'] ?? '');
            if ($requirementsStatus !== 'SPECIFICATION_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_PRODUCT_REQUIREMENTS_RESULT', $requirements, AgentRole::PRODUCT_REQUIREMENTS->value);
                if ($requirementsStatus === 'HUMAN_DECISION_REQUIRED') {
                    $this->humanGates->request(
                        $domainId,
                        $organizationId,
                        'DOMAIN_SPECIFICATION',
                        EngineeringDomainStatus::ANALYSIS,
                        $this->firstQuestion($requirements, 'Product / Requirements requires a human decision.'),
                        'Authoritative Domain requirements contain material ambiguity.',
                        ['agent_output' => $requirements],
                        AgentRole::PRODUCT_REQUIREMENTS->value,
                    );
                } else {
                    $this->domains->updateStatus(
                        $domainId,
                        $requirementsStatus === 'FAILED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                        'Domain Product / Requirements returned '.$requirementsStatus.'.',
                    );
                }
                return $this->view($domainId);
            }
    
            $domainSpec = is_array($requirements['domain_specification'] ?? null) ? $requirements['domain_specification'] : [];
            $domainSpec['key'] = $domain['domain_key'];
            $domainSpec['name'] = $domain['name'];
            $domainAc = is_array($requirements['domain_acceptance_criteria'] ?? null) ? $requirements['domain_acceptance_criteria'] : [];
            $capabilities = is_array($requirements['capabilities'] ?? null) ? $requirements['capabilities'] : [];
            if ($domainAc === [] || $capabilities === []) throw new RuntimeException('Domain Product / Requirements produced an incomplete specification.');
    
            $specArtifact = $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, $domainSpec, AgentRole::PRODUCT_REQUIREMENTS->value);
            $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ACCEPTANCE_CRITERIA->value, ['criteria' => $domainAc], AgentRole::PRODUCT_REQUIREMENTS->value);
            $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::CAPABILITY_MAP->value, ['capabilities' => $capabilities], AgentRole::PRODUCT_REQUIREMENTS->value);
            $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::CAPABILITY_SPECIFICATION->value, ['capabilities' => $capabilities], AgentRole::PRODUCT_REQUIREMENTS->value);
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::DECOMPOSITION->value);
            $this->domains->recordRuntimeEvent(
                $domainId,
                $organizationId,
                EngineeringDomainRuntimeEventType::DOMAIN_SPECIFICATION_READY->value,
                null,
                ['artifact_id' => $specArtifact['id'], 'version' => $specArtifact['version'], 'acceptance_criteria' => count($domainAc), 'capabilities' => count($capabilities)],
                $correlationId,
                'domain-specification-ready:v'.$specArtifact['version'],
            );
    
    
        }

        if ($resumeFromQaPlan) {
            $qaPlanArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN);
            $qaPlan = is_array($qaPlanArtifact['content'] ?? null) ? $qaPlanArtifact['content'] : [];
            if ($qaPlan === []) throw new RuntimeException('Persisted ARCHITECTURE state is missing Domain QA Plan.');
        } else {
            $qa = $this->agents->run(
                $domainId,
                $organizationId,
                AgentRole::QA_PLANNER,
                'Create an independent Domain QA Plan before architecture and implementation.',
                [
                    'phase' => 'PLAN',
                    'domain_specification' => $domainSpec,
                    'domain_acceptance_criteria' => $domainAc,
                    'capabilities' => $capabilities,
                    'human_decision_history' => $this->domains->humanDecisionHistory($domainId),
                ],
                $correlationId.':qa-plan',
            );
            if (($qa['status'] ?? null) !== 'PLAN_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_QA_PLANNING_RESULT', $qa, AgentRole::QA_PLANNER->value);
                if (($qa['status'] ?? null) === 'HUMAN_TEST_REQUIRED') {
                    $this->humanGates->request(
                        $domainId,
                        $organizationId,
                        'DOMAIN_SPECIFICATION',
                        EngineeringDomainStatus::DECOMPOSITION,
                        'QA Planner requires human input before the Domain test contract can be finalized.',
                        'At least one Domain verification requirement cannot be safely resolved automatically.',
                        ['human_tests_required' => $qa['human_tests_required'] ?? [], 'blockers' => $qa['blockers'] ?? []],
                        AgentRole::QA_PLANNER->value,
                    );
                } else {
                    $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Domain QA planning did not reach PLAN_READY.');
                }
                return $this->view($domainId);
            }
            $qaPlan = is_array($qa['domain_qa_plan'] ?? null) ? $qa['domain_qa_plan'] : [];
            $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value, $qaPlan, AgentRole::QA_PLANNER->value);
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::ARCHITECTURE->value);
    
    
        }

        $repositoryIndexArtifact = $this->domains->latestArtifact(
            $domainId,
            EngineeringDomainArtifactType::REPOSITORY_CONTEXT_INDEX->value,
        );
        $repositoryIndexContent = $this->repositoryIndex->build();
        $indexedRevision = (string) ($repositoryIndexArtifact['content']['revision'] ?? '');
        if ($repositoryIndexArtifact === null || $indexedRevision !== (string) ($repositoryIndexContent['revision'] ?? '')) {
            $repositoryIndexArtifact = $this->domains->saveArtifact(
                $domainId,
                EngineeringDomainArtifactType::REPOSITORY_CONTEXT_INDEX->value,
                $repositoryIndexContent,
                'DOMAIN_RUNTIME',
            );
        }
        $compressedRepositoryIndex = $this->compressor->repositoryIndex(
            is_array($repositoryIndexArtifact['content'] ?? null) ? $repositoryIndexArtifact['content'] : [],
        );

        $architect = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::PRINCIPAL_ARCHITECT,
            'Create Domain Architecture, Architecture Constitution and dependency-safe feature decomposition for autonomous implementation.',
            [
                'domain_specification' => $domainSpec,
                'domain_acceptance_criteria' => $domainAc,
                'requirements_capabilities' => $capabilities,
                'manager_capabilities' => $capabilities,
                'domain_qa_plan' => $qaPlan,
                'human_decision_history' => $this->domains->humanDecisionHistory($domainId),
                'target_repository' => $domain['target_repository'],
                'target_branch' => $domain['target_branch'],
                'repository_context_index' => $compressedRepositoryIndex,
                'existing_code_awareness' => [
                    'check_equivalent_class' => true,
                    'check_shared_primitive' => true,
                    'check_existing_domain_contract' => true,
                ],
                'shared_kernel' => [
                    'primitives' => $this->sharedKernel->primitives(),
                    'reuse_rule' => $this->sharedKernel->reuseRule(),
                ],
            ],
            $correlationId.':architect',
        );

        $architectStatus = (string) ($architect['status'] ?? '');
        if (!in_array($architectStatus, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) {
            $this->domains->saveArtifact($domainId, 'DOMAIN_ARCHITECT_RESULT', $architect, AgentRole::PRINCIPAL_ARCHITECT->value);
            if ($architectStatus === 'NEEDS_HUMAN_DECISION') {
                $this->humanGates->request(
                    $domainId,
                    $organizationId,
                    'DOMAIN_ARCHITECTURE',
                    EngineeringDomainStatus::ARCHITECTURE,
                    'Principal Architect requires a human architecture decision.',
                    'The Domain Architecture contains a material choice outside autonomous policy.',
                    ['agent_output' => $architect],
                    AgentRole::PRINCIPAL_ARCHITECT->value,
                );
            } else {
                $this->domains->updateStatus(
                    $domainId,
                    $architectStatus === 'REJECTED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                    'Domain Architect returned '.$architectStatus.'.',
                );
            }
            return $this->view($domainId);
        }

        $features = is_array($architect['features'] ?? null) ? $architect['features'] : [];
        $dependencies = is_array($architect['dependencies'] ?? null) ? $architect['dependencies'] : [];
        $architectCapabilities = is_array($architect['capabilities'] ?? null) && $architect['capabilities'] !== []
            ? $architect['capabilities']
            : $capabilities;
        $architectCapabilities = $this->mergeCapabilityRequirements($architectCapabilities, $capabilities);
        $this->graph->assertValid($features, $dependencies);

        $architecture = is_array($architect['domain_architecture'] ?? null) ? $architect['domain_architecture'] : [];
        $architecture['repository_revision'] = (string) ($repositoryIndexArtifact['content']['revision'] ?? '');
        $architecture['repository_context_index_hash'] = (string) ($repositoryIndexArtifact['content']['hash'] ?? $repositoryIndexArtifact['content_hash'] ?? '');
        $constitution = is_array($architect['architecture_constitution'] ?? null) ? $architect['architecture_constitution'] : [];
        if ($architecture === [] || $constitution === [] || $features === []) {
            throw new RuntimeException('Domain Architect produced an incomplete architecture/decomposition.');
        }

        $architectureArtifact = $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, $architecture, AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION->value, $constitution, AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_ARCHITECTURE_APPROVED->value,
            null,
            ['artifact_id' => $architectureArtifact['id'], 'version' => $architectureArtifact['version'], 'status' => $architectStatus],
            $correlationId,
            'domain-architecture-approved:v'.$architectureArtifact['version'],
            actor: AgentRole::PRINCIPAL_ARCHITECT->value,
            reason: 'Domain Architecture passed the architecture gate.',
            artifactId: (string) $architectureArtifact['id'],
            result: $architectStatus,
        );
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::ARCHITECTURE_CHANGED->value,
            null,
            ['artifact_id' => $architectureArtifact['id'], 'version' => $architectureArtifact['version'], 'content_hash' => $architectureArtifact['content_hash']],
            $correlationId,
            'architecture-changed:v'.$architectureArtifact['version'],
            actor: AgentRole::PRINCIPAL_ARCHITECT->value,
            reason: 'New canonical Domain Architecture revision became active.',
            artifactId: (string) $architectureArtifact['id'],
            result: 'ACTIVE',
        );
        $decomposition = [
            'capabilities' => $architectCapabilities,
            'features' => $features,
            'dependencies' => $dependencies,
            'parallelization_groups' => $architect['parallelization_groups'] ?? [],
            'critical_path' => $architect['critical_path'] ?? [],
        ];
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_DECOMPOSITION->value, $decomposition, AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::FEATURE_DEPENDENCY_GRAPH->value, [
            'dependencies' => $dependencies,
            'parallelization_groups' => $architect['parallelization_groups'] ?? [],
            'critical_path' => $architect['critical_path'] ?? [],
        ], AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::MIGRATION_PLAN->value, $architect['migration_plan'], AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::INTEGRATION_STRATEGY->value, [
            'integration_strategy' => $architect['integration_strategy'] ?? null,
            'release_strategy' => $architect['release_strategy'] ?? null,
            'conditions' => $architect['conditions'] ?? [],
        ], AgentRole::PRINCIPAL_ARCHITECT->value);

        $this->domains->replacePlan($domainId, $architectCapabilities, $features, $dependencies);
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_DECOMPOSITION_READY->value,
            null,
            ['architecture_version' => $architectureArtifact['version'], 'features' => count($features), 'dependencies' => count($dependencies)],
            $correlationId,
            'domain-decomposition-ready:v'.$architectureArtifact['version'],
        );
        foreach ($architectCapabilities as $capability) {
            $key = trim((string) ($capability['key'] ?? ''));
            if ($key === '') continue;
            $this->domains->recordRuntimeEvent(
                $domainId,
                $organizationId,
                EngineeringDomainRuntimeEventType::CAPABILITY_READY->value,
                null,
                ['capability_key' => $key, 'architecture_version' => $architectureArtifact['version']],
                $correlationId,
                'capability-ready:'.$key.':v'.$architectureArtifact['version'],
            );
        }

        $contracts = is_array($architect['contracts'] ?? null) ? $architect['contracts'] : [];
        $this->domains->replaceContracts($domainId, $contracts);
        foreach ($contracts as $contract) {
            if (!is_array($contract)) continue;
            $key = trim((string) ($contract['key'] ?? $contract['name'] ?? ''));
            if ($key === '') continue;
            $version = trim((string) ($contract['version'] ?? 'v1'));
            $this->domains->recordRuntimeEvent(
                $domainId,
                $organizationId,
                EngineeringDomainRuntimeEventType::CONTRACT_CHANGED->value,
                null,
                ['contract_key' => $key, 'version' => $version, 'compatibility' => $contract['compatibility'] ?? null],
                $correlationId,
                'contract-changed:'.$key.':'.$version,
            );
        }
        $events = is_array($architect['events'] ?? null) ? $architect['events'] : [];
        $this->domains->replaceEvents($domainId, $events);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::CONTRACT_REGISTRY->value, [
            'contracts' => $this->domains->contracts($domainId),
        ], AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_EVENT_REGISTRY->value, [
            'events' => $this->domains->events($domainId),
        ], AgentRole::PRINCIPAL_ARCHITECT->value);

        $featureContexts = [];
        foreach ($this->domains->features($domainId) as $domainFeature) {
            $featureKey = (string) ($domainFeature['feature_key'] ?? '');
            if ($featureKey === '') continue;
            $featureContexts[$featureKey] = $this->context->forFeature($domainId, $featureKey);
        }
        ksort($featureContexts);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::FEATURE_CONTEXT_PACK->value, [
            'architecture_version' => (int) $architectureArtifact['version'],
            'features' => $featureContexts,
        ], AgentRole::PRINCIPAL_ARCHITECT->value);

        foreach ($contracts as $contract) {
            if (!is_array($contract)) continue;
            $compatibility = (string) ($contract['compatibility'] ?? '');
            if (!$this->policy->contractChangeRequiresHuman($compatibility)) continue;
            $this->humanGates->request(
                $domainId,
                $organizationId,
                'BREAKING_CONTRACT_'.strtoupper((string) ($contract['id'] ?? $contract['key'] ?? $contract['name'] ?? 'UNKNOWN')),
                EngineeringDomainStatus::READY_FOR_IMPLEMENTATION,
                'Approve breaking public contract '.((string) ($contract['name'] ?? $contract['id'] ?? 'unknown')).'?',
                'Engineering Policy forbids autonomous approval of BREAKING public contracts.',
                [
                    'contract' => $contract,
                    'architecture_artifact_id' => $architectureArtifact['id'],
                    'architecture_version' => $architectureArtifact['version'],
                ],
                'ENGINEERING_POLICY_ENGINE',
            );
        }

        $migrationPlan = is_array($architect['migration_plan'] ?? null) ? $architect['migration_plan'] : [];
        if ($this->policy->migrationRequiresHuman($migrationPlan)) {
            $this->humanGates->request(
                $domainId,
                $organizationId,
                'RISKY_MIGRATION',
                EngineeringDomainStatus::READY_FOR_IMPLEMENTATION,
                'Approve risky Domain migration plan?',
                'Engineering Policy requires a human decision for high-risk, destructive or downtime migrations.',
                [
                    'migration_plan' => $migrationPlan,
                    'architecture_artifact_id' => $architectureArtifact['id'],
                    'architecture_version' => $architectureArtifact['version'],
                ],
                'ENGINEERING_POLICY_ENGINE',
            );
        }

        $proposedFlags = is_array($architecture['feature_flags'] ?? null) ? $architecture['feature_flags'] : [];
        if (($proposedFlags['PRODUCTION_EXECUTION_ENABLED'] ?? false) === true) {
            $this->humanGates->request(
                $domainId,
                $organizationId,
                'EXTERNAL_PRODUCTION_INTEGRATION',
                EngineeringDomainStatus::READY_FOR_IMPLEMENTATION,
                'Approve architecture that proposes production execution?',
                'Production execution and external production integrations are outside autonomous Engineering authority.',
                [
                    'proposed_feature_flags' => $proposedFlags,
                    'architecture_artifact_id' => $architectureArtifact['id'],
                    'architecture_version' => $architectureArtifact['version'],
                ],
                'ENGINEERING_POLICY_ENGINE',
            );
        }

        if ($this->domains->openHumanDecisions($domainId) !== []) {
            $this->artifactGraph->rebuild($domainId);
            return $this->view($domainId);
        }

        $this->domains->updateStatus($domainId, EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value);
        $this->artifactGraph->rebuild($domainId);

        return $this->view($domainId);
    }

    /** @return array<string,mixed> */
    public function view(string $domainId): array
    {
        return [
            'domain' => $this->domains->domain($domainId),
            'capabilities' => $this->domains->capabilities($domainId),
            'features' => $this->domains->features($domainId),
            'dependencies' => $this->domains->dependencies($domainId),
            'contracts' => $this->domains->contracts($domainId),
            'events' => $this->domains->events($domainId),
            'artifacts' => $this->domains->artifacts($domainId),
            'artifact_dependencies' => $this->domains->artifactDependencies($domainId),
            'path_reservations' => $this->domains->pathReservations($domainId),
            'agent_runs' => $this->domains->agentRuns($domainId),
            'runtime_events' => $this->domains->runtimeEvents($domainId),
            'open_human_decisions' => $this->domains->openHumanDecisions($domainId),
            'human_decision_history' => $this->domains->humanDecisionHistory($domainId),
            'analytics' => $this->analytics->snapshot($domainId),
        ];
    }

    /** @param array<string,mixed> $output */
    private function firstQuestion(array $output, string $fallback): string
    {
        foreach (is_array($output['open_questions'] ?? null) ? $output['open_questions'] : [] as $question) {
            if (is_string($question) && trim($question) !== '') return trim($question);
            if (is_array($question) && trim((string) ($question['question'] ?? '')) !== '') {
                return trim((string) $question['question']);
            }
        }
        return $fallback;
    }

    /** @param list<array<string,mixed>> $architectCapabilities @param list<array<string,mixed>> $requirementsCapabilities @return list<array<string,mixed>> */
    private function mergeCapabilityRequirements(array $architectCapabilities, array $requirementsCapabilities): array
    {
        $requirements = [];
        foreach ($requirementsCapabilities as $capability) {
            if (!is_array($capability)) continue;
            $key = trim((string) ($capability['key'] ?? ''));
            if ($key !== '') $requirements[$key] = $capability;
        }

        $merged = [];
        foreach ($architectCapabilities as $capability) {
            if (!is_array($capability)) continue;
            $key = trim((string) ($capability['key'] ?? ''));
            if ($key === '') continue;
            $source = $requirements[$key] ?? [];
            $capability['acceptance_criteria'] = is_array($source['acceptance_criteria'] ?? null)
                ? array_values($source['acceptance_criteria'])
                : [];
            $capability['required'] = array_key_exists('required', $source)
                ? (bool) $source['required']
                : (bool) ($capability['required'] ?? true);
            $capability['depends_on'] = is_array($source['depends_on'] ?? null)
                ? array_values($source['depends_on'])
                : (is_array($capability['depends_on'] ?? null) ? array_values($capability['depends_on']) : []);
            $merged[] = $capability;
        }

        return $merged;
    }

    private function requiredArtifact(string $domainId, EngineeringDomainArtifactType $type): array
    {
        $artifact = $this->domains->latestArtifact($domainId, $type->value);
        if ($artifact === null) throw new RuntimeException('Domain planning recovery missing required artifact '.$type->value.'.');
        return $artifact;
    }

    /** @param array<string,mixed> $domain */
    private function assertTenant(array $domain, string $organizationId): void
    {
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
    }
}
