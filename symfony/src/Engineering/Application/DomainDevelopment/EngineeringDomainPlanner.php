<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use RuntimeException;

final readonly class EngineeringDomainPlanner
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainAgentService $agents,
        private FeatureDependencyGraph $graph = new FeatureDependencyGraph(),
    ) {}

    /** @return array<string,mixed> */
    public function plan(string $domainId, string $organizationId, string $correlationId): array
    {
        $domain = $this->domains->domain($domainId);
        $this->assertTenant($domain, $organizationId);
        if (!in_array($domain['status'], [
            EngineeringDomainStatus::DRAFT->value,
            EngineeringDomainStatus::BLOCKED->value,
            EngineeringDomainStatus::FAILED->value,
        ], true)) {
            throw new RuntimeException('Domain planning can start only from DRAFT/BLOCKED/FAILED; current status is '.$domain['status'].'.');
        }

        $this->domains->updateStatus($domainId, EngineeringDomainStatus::ANALYSIS->value);
        $manager = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::ENGINEERING_MANAGER,
            'Formalize the Master Domain Specification into a canonical Domain Specification, Domain Acceptance Criteria and capability map.',
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

        $domainSpec = is_array($manager['domain_specification'] ?? null) ? $manager['domain_specification'] : [];
        $domainSpec['key'] = $domain['domain_key'];
        $domainSpec['name'] = $domain['name'];
        $domainAc = is_array($manager['domain_acceptance_criteria'] ?? null) ? $manager['domain_acceptance_criteria'] : [];
        $capabilities = is_array($manager['capabilities'] ?? null) ? $manager['capabilities'] : [];
        if ($domainAc === [] || $capabilities === []) throw new RuntimeException('Domain Manager produced an incomplete specification.');

        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_SPECIFICATION->value, $domainSpec, AgentRole::ENGINEERING_MANAGER->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ACCEPTANCE_CRITERIA->value, ['criteria' => $domainAc], AgentRole::ENGINEERING_MANAGER->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::CAPABILITY_MAP->value, ['capabilities' => $capabilities], AgentRole::ENGINEERING_MANAGER->value);
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::DECOMPOSITION->value);

        $qa = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::QA,
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
            $this->domains->saveArtifact($domainId, 'DOMAIN_QA_PLANNING_RESULT', $qa, AgentRole::QA->value);
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Domain QA planning did not reach PLAN_READY.');
            return $this->view($domainId);
        }
        $qaPlan = is_array($qa['domain_qa_plan'] ?? null) ? $qa['domain_qa_plan'] : [];
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN->value, $qaPlan, AgentRole::QA->value);
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::ARCHITECTURE->value);

        $architect = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::PRINCIPAL_ARCHITECT,
            'Create Domain Architecture, Architecture Constitution and dependency-safe feature decomposition for autonomous implementation.',
            [
                'domain_specification' => $domainSpec,
                'domain_acceptance_criteria' => $domainAc,
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

        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value, $architecture, AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION->value, $constitution, AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_DECOMPOSITION->value, [
            'capabilities' => $architectCapabilities,
            'features' => $features,
            'dependencies' => $dependencies,
            'parallelization_groups' => $architect['parallelization_groups'] ?? [],
            'critical_path' => $architect['critical_path'] ?? [],
        ], AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::MIGRATION_PLAN->value, is_array($architect['migration_plan'] ?? null) ? $architect['migration_plan'] : ['plan' => $architect['migration_plan'] ?? null], AgentRole::PRINCIPAL_ARCHITECT->value);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::INTEGRATION_STRATEGY->value, [
            'integration_strategy' => $architect['integration_strategy'] ?? null,
            'release_strategy' => $architect['release_strategy'] ?? null,
            'conditions' => $architect['conditions'] ?? [],
        ], AgentRole::PRINCIPAL_ARCHITECT->value);

        $this->domains->replacePlan($domainId, $architectCapabilities, $features, $dependencies);
        $this->domains->replaceContracts($domainId, is_array($architect['contracts'] ?? null) ? $architect['contracts'] : []);
        $this->domains->replaceEvents($domainId, is_array($architect['events'] ?? null) ? $architect['events'] : []);
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
        ];
    }

    /** @param array<string,mixed> $domain */
    private function assertTenant(array $domain, string $organizationId): void
    {
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
    }
}
