<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
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
                ],
                $correlationId.':manager',
            );
    
            $managerStatus = (string) ($manager['status'] ?? '');
            if ($managerStatus !== 'SPECIFICATION_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_MANAGER_RESULT', $manager, AgentRole::ENGINEERING_MANAGER->value);
                $this->domains->updateStatus(
                    $domainId,
                    $managerStatus === 'FAILED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                    'Domain Manager returned '.$managerStatus.'.',
                );
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
                    'target_repository' => $domain['target_repository'],
                    'target_branch' => $domain['target_branch'],
                ],
                $correlationId.':product-requirements',
            );
    
            $requirementsStatus = (string) ($requirements['status'] ?? '');
            if ($requirementsStatus !== 'SPECIFICATION_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_PRODUCT_REQUIREMENTS_RESULT', $requirements, AgentRole::PRODUCT_REQUIREMENTS->value);
                $this->domains->updateStatus(
                    $domainId,
                    $requirementsStatus === 'FAILED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                    'Domain Product / Requirements returned '.$requirementsStatus.'.',
                );
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
                ],
                $correlationId.':qa-plan',
            );
            if (($qa['status'] ?? null) !== 'PLAN_READY') {
                $this->domains->saveArtifact($domainId, 'DOMAIN_QA_PLANNING_RESULT', $qa, AgentRole::QA_PLANNER->value);
                $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Domain QA planning did not reach PLAN_READY.');
                return $this->view($domainId);
            }
            $qaPlan = is_array($qa['domain_qa_plan'] ?? null) ? $qa['domain_qa_plan'] : [];
            $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value, $qaPlan, AgentRole::QA_PLANNER->value);
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::ARCHITECTURE->value);
    
    
        }

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
                'target_repository' => $domain['target_repository'],
                'target_branch' => $domain['target_branch'],
            ],
            $correlationId.':architect',
        );

        $architectStatus = (string) ($architect['status'] ?? '');
        if (!in_array($architectStatus, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) {
            $this->domains->saveArtifact($domainId, 'DOMAIN_ARCHITECT_RESULT', $architect, AgentRole::PRINCIPAL_ARCHITECT->value);
            $this->domains->updateStatus(
                $domainId,
                $architectStatus === 'REJECTED' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value,
                'Domain Architect returned '.$architectStatus.'.',
            );
            return $this->view($domainId);
        }

        $features = is_array($architect['features'] ?? null) ? $architect['features'] : [];
        $dependencies = is_array($architect['dependencies'] ?? null) ? $architect['dependencies'] : [];
        $architectCapabilities = is_array($architect['capabilities'] ?? null) && $architect['capabilities'] !== []
            ? $architect['capabilities']
            : $capabilities;
        $this->graph->assertValid($features, $dependencies);

        $architecture = is_array($architect['domain_architecture'] ?? null) ? $architect['domain_architecture'] : [];
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
        );
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::ARCHITECTURE_CHANGED->value,
            null,
            ['artifact_id' => $architectureArtifact['id'], 'version' => $architectureArtifact['version'], 'content_hash' => $architectureArtifact['content_hash']],
            $correlationId,
            'architecture-changed:v'.$architectureArtifact['version'],
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

        $this->domains->updateStatus($domainId, EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value);

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
            'path_reservations' => $this->domains->pathReservations($domainId),
            'agent_runs' => $this->domains->agentRuns($domainId),
            'runtime_events' => $this->domains->runtimeEvents($domainId),
        ];
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
