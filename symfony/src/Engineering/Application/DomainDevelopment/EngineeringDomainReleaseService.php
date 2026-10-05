<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
use RuntimeException;

final readonly class EngineeringDomainReleaseService
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringStatusService $engineering,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringDomainAgentService $agents,
        private EngineeringDomainAgentOutputValidator $validator,
        private EngineeringDomainDriftDetector $drift,
        private EngineeringArtifactDependencyGraph $artifactGraph,
        private EngineeringDomainDocumentationService $documentation,
        private EngineeringDomainHumanGateService $humanGates,
    ) {}

    /** @return array<string,mixed> */
    public function verify(string $domainId, string $organizationId, string $correlationId): array
    {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
        if (!in_array($domain['status'], [
            EngineeringDomainStatus::INTEGRATION->value,
            EngineeringDomainStatus::DOMAIN_QA->value,
            EngineeringDomainStatus::BLOCKED->value,
        ], true)) {
            throw new RuntimeException('Domain QA can run only after implementation/integration; current status is '.$domain['status'].'.');
        }

        $completedCycles = 0;
        foreach ($this->domains->runtimeEvents($domainId, 500) as $event) {
            if (($event['event_type'] ?? null) === EngineeringDomainRuntimeEventType::DOMAIN_QA_COMPLETED->value) ++$completedCycles;
        }
        $authorizedCycles = $this->authorizedIntegrationCycles(
            $domainId,
            max(1, (int) ($domain['max_domain_integration_cycles'] ?? 3)),
        );
        if ($completedCycles >= $authorizedCycles) {
            $decisionId = $this->humanGates->request(
                $domainId,
                $organizationId,
                'DOMAIN_INTEGRATION_BUDGET',
                EngineeringDomainStatus::INTEGRATION,
                'Extend Domain integration and QA cycle budget?',
                'Domain integration/QA exhausted its configured cycle budget.',
                [
                    'completed_cycles' => $completedCycles,
                    'authorized_cycles' => $authorizedCycles,
                    'base_cycle_limit' => (int) ($domain['max_domain_integration_cycles'] ?? 3),
                ],
                'DOMAIN_RELEASE_RUNTIME',
                [
                    ['id' => 'CONTINUE', 'description' => 'Authorize another integration/QA cycle window.'],
                    ['id' => 'CANCEL', 'description' => 'Stop further Domain integration cycles.'],
                ],
            );
            foreach ($this->domains->openHumanDecisions($domainId) as $decision) {
                if (($decision['id'] ?? null) === $decisionId) {
                    return $this->view($domainId, [
                        'human_approval_required' => true,
                        'open_human_decisions' => $this->domains->openHumanDecisions($domainId),
                    ]);
                }
            }
        }

        $drift = $this->drift->refresh($domainId);
        if ($drift !== []) {
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Architecture or contract drift requires feature revalidation.');
            return $this->view($domainId, ['drift' => $drift]);
        }

        $features = $this->domains->features($domainId);
        $required = array_values(array_filter($features, static fn (array $feature): bool => (bool) $feature['required']));
        if ($required === []) throw new RuntimeException('Domain release requires at least one required feature.');
        foreach ($required as $feature) {
            if (($feature['status'] ?? null) !== EngineeringDomainFeatureStatus::COMPLETED->value) {
                throw new RuntimeException('Domain QA cannot start before required feature is completed: '.$feature['feature_key']);
            }
        }

        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_INTEGRATION_COMPLETED->value,
            null,
            ['required_features_complete' => true],
            $correlationId,
            'domain-integration-completed:v'.((string) ($domain['version'] ?? 1)),
            actor: AgentRole::INTEGRATION_RELEASE->value,
            reason: 'All required Feature workflows completed and Domain integration prerequisites were satisfied.',
            result: 'COMPLETED',
        );
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::DOMAIN_QA->value);
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_QA_STARTED->value,
            null,
            ['domain_version' => (int) ($domain['version'] ?? 1)],
            $correlationId,
            'domain-qa-started:v'.((string) ($domain['version'] ?? 1)),
            actor: AgentRole::QA_EXECUTOR->value,
            reason: 'Integrated Domain entered independent Domain QA.',
            result: EngineeringDomainStatus::DOMAIN_QA->value,
        );
        $featureEvidence = [];
        foreach ($features as $feature) {
            $engineeringFeatureId = $feature['engineering_feature_id'] ?? null;
            if (!is_string($engineeringFeatureId) || $engineeringFeatureId === '') continue;
            $status = $this->engineering->status($engineeringFeatureId);
            $qa = $status['artifacts']['QA_REPORT']['content'] ?? null;
            $review = $status['artifacts']['REVIEW_REPORT']['content'] ?? null;
            $development = $status['artifacts']['DEVELOPMENT_RESULT']['content'] ?? null;
            $final = $status['artifacts']['FINAL_REPORT']['content'] ?? null;
            $findings = is_array($status['findings'] ?? null) ? $status['findings'] : [];
            $blockingFindings = array_values(array_filter($findings, static function (mixed $finding): bool {
                if (!is_array($finding) || strtoupper((string) ($finding['status'] ?? 'OPEN')) !== 'OPEN') return false;
                return in_array(strtoupper((string) ($finding['severity'] ?? '')), ['BLOCKER','MAJOR','CRITICAL','HIGH'], true);
            }));
            if ($blockingFindings !== []) {
                throw new RuntimeException('Domain release blocked by open BLOCKER/MAJOR finding in feature '.$feature['feature_key'].'.');
            }
            if ((bool) $feature['required']) {
                if (!is_array($qa) || ($qa['status'] ?? null) !== 'PASS') {
                    throw new RuntimeException('Required Domain feature lacks QA PASS evidence: '.$feature['feature_key']);
                }
                if (!is_array($review) || ($review['status'] ?? null) !== 'APPROVED') {
                    throw new RuntimeException('Required Domain feature lacks Reviewer approval: '.$feature['feature_key']);
                }
            }
            $featureEvidence[] = [
                'feature_key' => $feature['feature_key'],
                'kind' => $feature['kind'],
                'risk' => $feature['risk'],
                'required' => $feature['required'],
                'engineering_feature_id' => $engineeringFeatureId,
                'engineering_status' => $status['feature']['status'] ?? null,
                'review' => $review,
                'qa' => $qa,
                'development' => $development,
                'final_report' => $final,
                'findings' => $findings,
            ];
        }

        $qaPlanArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_PLAN);
        $domainAcArtifact = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ACCEPTANCE_CRITERIA);
        $architecture = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE);
        $constitution = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE_CONSTITUTION);
        $migration = $this->requiredArtifact($domainId, EngineeringDomainArtifactType::MIGRATION_PLAN);

        $repositoryRevision = null;
        $ci = ['state' => 'UNAVAILABLE', 'total' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0, 'checks' => []];
        if ($this->repository->available()) {
            $repositoryRevision = $this->repository->currentBaseRevision((string) $domain['target_branch']);
            $ci = $this->repository->commitChecks($repositoryRevision);
            if (
                strtoupper((string) ($ci['state'] ?? '')) !== 'SUCCESS'
                || (int) ($ci['failed'] ?? 0) > 0
                || (int) ($ci['pending'] ?? 0) > 0
            ) {
                $this->domains->updateStatus(
                    $domainId,
                    EngineeringDomainStatus::BLOCKED->value,
                    'Integrated repository revision requires green CI before Domain QA.',
                );
                return $this->view($domainId, ['repository_revision' => $repositoryRevision, 'ci' => $ci]);
            }
        }

        $result = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::QA_EXECUTOR,
            'Verify the integrated Domain against Domain Acceptance Criteria and the independent Domain QA Plan using only supplied evidence.',
            [
                'phase' => 'EXECUTION',
                'domain' => $domain,
                'domain_acceptance_criteria' => $domainAcArtifact['content']['criteria'] ?? [],
                'domain_qa_plan' => $qaPlanArtifact['content'],
                'domain_architecture' => $architecture['content'],
                'architecture_constitution' => $constitution['content'],
                'migration_plan' => $migration['content'],
                'features' => $featureEvidence,
                'contracts' => $this->domains->contracts($domainId),
                'events' => $this->domains->events($domainId),
                'repository_revision' => $repositoryRevision,
                'repository_ci' => $ci,
            ],
            $correlationId.':domain-qa',
        );
        $this->validator->validate(AgentRole::QA_EXECUTOR, $result, 'EXECUTION');
        if (($result['status'] ?? null) === 'PASS') {
            $this->assertDomainAcceptanceCoverage(
                is_array($domainAcArtifact['content']['criteria'] ?? null) ? $domainAcArtifact['content']['criteria'] : [],
                is_array($result['acceptance_criteria'] ?? null) ? $result['acceptance_criteria'] : [],
            );
        }

        $report = array_merge($result, [
            'tested_revision' => $repositoryRevision,
            'feature_evidence' => $featureEvidence,
            'ci' => $ci,
            'architecture_version' => (int) $architecture['version'],
            'architecture_hash' => $architecture['content_hash'],
        ]);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, $report, AgentRole::QA_EXECUTOR->value);
        $this->artifactGraph->rebuild($domainId);

        $qaStatus = (string) ($result['status'] ?? '');
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_QA_COMPLETED->value,
            null,
            ['status' => $qaStatus, 'tested_revision' => $repositoryRevision],
            $correlationId,
            'domain-qa-completed:v'.((string) ($domain['version'] ?? 1)).':'.$qaStatus.':'.($repositoryRevision ?? 'none'),
            actor: AgentRole::QA_EXECUTOR->value,
            reason: 'Domain QA completed against the integrated repository revision.',
            repositoryRevision: $repositoryRevision,
            result: $qaStatus,
        );
        if ($qaStatus !== 'PASS') {
            $state = $qaStatus === 'FAIL' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value;
            $this->domains->updateStatus($domainId, $state, 'Domain QA returned '.$qaStatus.'.');
            return $this->view($domainId, ['qa_status' => $qaStatus]);
        }

        $documentation = $this->documentation->generate($domainId);
        foreach ([
            'public' => EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_PUBLIC,
            'integrator' => EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_INTEGRATOR,
            'developer' => EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_DEVELOPER,
            'translations' => EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS,
        ] as $key => $type) {
            if (!isset($documentation[$key]) || ($documentation[$key]['type'] ?? null) !== $type->value) {
                throw new RuntimeException('Domain documentation generation did not produce '.$type->value.'.');
            }
        }

        $integrationRelease = $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::INTEGRATION_RELEASE,
            'Assess integrated Domain release readiness after Domain QA and before the human release gate.',
            [
                'domain' => $domain,
                'features' => $featureEvidence,
                'domain_architecture' => $architecture['content'],
                'architecture_constitution' => $constitution['content'],
                'migration_plan' => $migration['content'],
                'contracts' => $this->domains->contracts($domainId),
                'events' => $this->domains->events($domainId),
                'domain_qa_report' => $report,
                'repository_revision' => $repositoryRevision,
                'repository_ci' => $ci,
                'integration_strategy' => $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::INTEGRATION_STRATEGY->value)['content'] ?? [],
                'documentation' => array_map(static fn (array $artifact): array => [
                    'artifact_id' => $artifact['id'],
                    'type' => $artifact['type'],
                    'version' => $artifact['version'],
                    'hash' => $artifact['content_hash'],
                ], $documentation),
            ],
            $correlationId.':integration-release',
        );
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_INTEGRATION_RELEASE_REPORT->value, $integrationRelease, AgentRole::INTEGRATION_RELEASE->value);
        $this->artifactGraph->rebuild($domainId);

        $integrationStatus = (string) ($integrationRelease['status'] ?? '');
        if ($integrationStatus !== 'RELEASE_READY') {
            $state = $integrationStatus === 'FAILED'
                ? EngineeringDomainStatus::FAILED->value
                : EngineeringDomainStatus::BLOCKED->value;
            $this->domains->updateStatus($domainId, $state, 'Integration & Release Agent returned '.$integrationStatus.'.');
            return $this->view($domainId, ['integration_release' => $integrationRelease]);
        }

        $this->completeCapabilities($domainId, $features, $result);
        $this->assertMandatoryCapabilitiesComplete($domainId);

        $integrationPullRequest = null;
        if (
            $this->repository->available()
            && trim((string) $domain['target_branch']) !== $this->repository->configuredBaseBranch()
        ) {
            $integrationPullRequest = $this->repository->openPullRequest(
                branch: (string) $domain['target_branch'],
                title: 'Engineering Domain: '.$domain['name'].' v'.$domain['version'],
                body: 'Domain Development Runtime V2 release candidate for '.$domain['domain_key'].'. Human merge remains mandatory.',
                baseBranch: $this->repository->configuredBaseBranch(),
            );
        }

        $manifest = $this->releaseManifest(
            $domain,
            $features,
            $architecture,
            $repositoryRevision,
            $ci,
            $report,
            $integrationRelease,
            $integrationPullRequest,
        );
        $this->domains->saveArtifact(
            $domainId,
            EngineeringDomainArtifactType::DOMAIN_RELEASE_MANIFEST->value,
            $manifest,
            'DOMAIN_RUNTIME',
        );
        $this->artifactGraph->rebuild($domainId);
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::RELEASE_READY->value);
        $this->domains->recordRuntimeEvent(
            $domainId,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_RELEASE_READY->value,
            null,
            [
                'repository_revision' => $repositoryRevision,
                'integration_pull_request' => $integrationPullRequest,
                'release_manifest_generated' => true,
            ],
            $correlationId,
            'domain-release-ready:v'.((string) ($domain['version'] ?? 1)).':'.($repositoryRevision ?? 'none'),
            actor: AgentRole::INTEGRATION_RELEASE->value,
            reason: 'Domain passed QA, integration and release-readiness gates and is awaiting human approval.',
            repositoryRevision: $repositoryRevision,
            result: EngineeringDomainStatus::RELEASE_READY->value,
        );

        return $this->view($domainId, ['release_manifest' => $manifest]);
    }

    /** @return array<string,mixed> */
    public function approve(string $domainId, string $approvedBy): array
    {
        $domain = $this->domains->domain($domainId);
        if (($domain['status'] ?? null) !== EngineeringDomainStatus::RELEASE_READY->value) {
            throw new RuntimeException('Only RELEASE_READY Domain can be approved.');
        }
        $manifest = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_RELEASE_MANIFEST->value);
        $qa = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value);
        if ($manifest === null || $qa === null || ($qa['content']['status'] ?? null) !== 'PASS') {
            throw new RuntimeException('Domain release approval requires Release Manifest and Domain QA PASS.');
        }
        $this->assertMandatoryCapabilitiesComplete($domainId);

        $integrationPullRequest = $manifest['content']['integration_pull_request'] ?? null;
        if (is_array($integrationPullRequest) && (int) ($integrationPullRequest['number'] ?? 0) > 0) {
            $pr = $this->repository->pullRequest((int) $integrationPullRequest['number']);
            if (($pr['merged'] ?? false) !== true) {
                throw new RuntimeException('Domain integration pull request must be merged before Domain can be completed.');
            }
        }

        $approvedManifest = $manifest['content'];
        $approvedManifest['human_approval'] = [
            'approved_by' => $approvedBy,
            'approved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_RELEASE_MANIFEST->value, $approvedManifest, $approvedBy);
        $this->artifactGraph->rebuild($domainId);
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::COMPLETED->value);

        return $this->view($domainId, ['approved' => true]);
    }

    /** @param array<string,mixed> $domain @param list<array<string,mixed>> $features @param array<string,mixed> $architecture @param array<string,mixed> $ci @param array<string,mixed> $qa @param array<string,mixed> $integrationRelease @param array<string,mixed>|null $integrationPullRequest */
    private function releaseManifest(array $domain, array $features, array $architecture, ?string $revision, array $ci, array $qa, array $integrationRelease, ?array $integrationPullRequest): array
    {
        return [
            'domain' => [
                'id' => $domain['id'],
                'key' => $domain['domain_key'],
                'name' => $domain['name'],
                'version' => (int) $domain['version'],
            ],
            'repository_revision' => $revision,
            'integration_branch' => $domain['target_branch'],
            'base_branch' => $this->repository->available() ? $this->repository->configuredBaseBranch() : null,
            'integration_pull_request' => $integrationPullRequest,
            'included_features' => array_map(static fn (array $feature): array => [
                'feature_key' => $feature['feature_key'],
                'engineering_feature_id' => $feature['engineering_feature_id'],
                'kind' => $feature['kind'],
                'required' => $feature['required'],
                'status' => $feature['status'],
            ], $features),
            'architecture' => [
                'version' => (int) $architecture['version'],
                'hash' => $architecture['content_hash'],
            ],
            'capabilities' => $this->domains->capabilities((string) $domain['id']),
            'contracts' => $this->domains->contracts((string) $domain['id']),
            'events' => $this->domains->events((string) $domain['id']),
            'migration_plan' => $this->domains->latestArtifact((string) $domain['id'], EngineeringDomainArtifactType::MIGRATION_PLAN->value)['content'] ?? [],
            'feature_flags' => $this->domains->latestArtifact((string) $domain['id'], EngineeringDomainArtifactType::DOMAIN_FEATURE_FLAGS->value)['content'] ?? [
                'DOMAIN_ENABLED' => false,
                'FEATURE_ENABLED' => [],
                'INTEGRATION_ENABLED' => false,
                'PRODUCTION_EXECUTION_ENABLED' => false,
            ],
            'proposed_feature_flags' => $architecture['content']['feature_flags'] ?? [],
            'documentation' => array_values(array_filter(array_map(
                function (EngineeringDomainArtifactType $type) use ($domain): ?array {
                    $artifact = $this->domains->latestArtifact((string) $domain['id'], $type->value);
                    if ($artifact === null) return null;
                    return [
                        'artifact_id' => $artifact['id'],
                        'type' => $artifact['type'],
                        'version' => $artifact['version'],
                        'hash' => $artifact['content_hash'],
                    ];
                },
                [
                    EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_PUBLIC,
                    EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_INTEGRATOR,
                    EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_DEVELOPER,
                    EngineeringDomainArtifactType::DOMAIN_DOCUMENTATION_TRANSLATIONS,
                ],
            ))),
            'qa_result' => [
                'status' => $qa['status'] ?? null,
                'tested_revision' => $qa['tested_revision'] ?? null,
                'acceptance_criteria' => $qa['acceptance_criteria'] ?? [],
                'defects' => $qa['defects'] ?? [],
            ],
            'integration_release' => $integrationRelease,
            'ci' => $ci,
            'rollback_plan' => $this->domains->latestArtifact((string) $domain['id'], EngineeringDomainArtifactType::MIGRATION_PLAN->value)['content']['rollback_strategy'] ?? null,
            'known_limitations' => $qa['known_limitations'] ?? [],
            'audit_trail' => [
                'artifacts' => array_map(static fn (array $artifact): array => [
                    'id' => $artifact['id'] ?? null,
                    'type' => $artifact['type'] ?? null,
                    'version' => $artifact['version'] ?? null,
                    'content_hash' => $artifact['content_hash'] ?? null,
                    'created_by' => $artifact['created_by'] ?? null,
                ], $this->domains->artifacts((string) $domain['id'])),
                'agent_runs' => array_map(static fn (array $run): array => [
                    'id' => $run['id'] ?? null,
                    'agent_role' => $run['agent_role'] ?? null,
                    'status' => $run['status'] ?? null,
                    'correlation_id' => $run['correlation_id'] ?? null,
                    'provider' => $run['provider'] ?? null,
                    'model' => $run['model'] ?? null,
                    'created_at' => $run['created_at'] ?? null,
                ], $this->domains->agentRuns((string) $domain['id'])),
                'runtime_events' => $this->domains->runtimeEvents((string) $domain['id'], 500),
                'artifact_dependencies' => $this->domains->artifactDependencies((string) $domain['id']),
            ],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function authorizedIntegrationCycles(string $domainId, int $baseLimit): int
    {
        $extensions = 0;
        foreach ($this->domains->humanDecisionHistory($domainId) as $decision) {
            if (($decision['gate_type'] ?? null) !== 'DOMAIN_INTEGRATION_BUDGET') continue;
            if (($decision['status'] ?? null) !== 'ANSWERED') continue;
            $selected = strtoupper(trim((string) ($decision['answer']['selected_option'] ?? '')));
            if (in_array($selected, ['APPROVE','CONTINUE'], true)) ++$extensions;
        }
        return max(1, $baseLimit) * (1 + $extensions);
    }

    /** @param list<array<string,mixed>> $features @param array<string,mixed> $qaResult */
    private function completeCapabilities(string $domainId, array $features, array $qaResult): void
    {
        $qaById = [];
        foreach (is_array($qaResult['acceptance_criteria'] ?? null) ? $qaResult['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) continue;
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id !== '') $qaById[$id] = $criterion;
        }

        foreach ($this->domains->capabilities($domainId) as $capability) {
            $capabilityKey = (string) ($capability['capability_key'] ?? '');
            if ($capabilityKey === '') continue;
            $requiredMembers = array_values(array_filter(
                $features,
                static fn (array $feature): bool =>
                    ($feature['capability_key'] ?? null) === $capabilityKey
                    && (bool) ($feature['required'] ?? true),
            ));
            if ($requiredMembers === [] && (bool) ($capability['required'] ?? false)) {
                throw new RuntimeException('Required capability '.$capabilityKey.' has no required implementation features.');
            }
            foreach ($requiredMembers as $feature) {
                if (($feature['status'] ?? null) !== EngineeringDomainFeatureStatus::COMPLETED->value) {
                    throw new RuntimeException('Capability '.$capabilityKey.' cannot complete before feature '.$feature['feature_key'].' is complete.');
                }
            }

            $metadata = is_array($capability['metadata'] ?? null) ? $capability['metadata'] : [];
            $criterionIds = is_array($metadata['acceptance_criteria'] ?? null) ? $metadata['acceptance_criteria'] : [];
            if ((bool) ($capability['required'] ?? false) && $criterionIds === []) {
                throw new RuntimeException('Required capability '.$capabilityKey.' has no acceptance criteria.');
            }
            foreach ($criterionIds as $criterionId) {
                $criterionId = strtoupper(trim((string) $criterionId));
                $evidence = $qaById[$criterionId] ?? null;
                if (!is_array($evidence) || ($evidence['status'] ?? null) !== 'PASS' || !$this->meaningfulEvidence($evidence['evidence'] ?? null)) {
                    throw new RuntimeException('Capability '.$capabilityKey.' acceptance criterion '.$criterionId.' did not PASS with evidence.');
                }
            }

            $this->domains->updateCapabilityStatus(
                $domainId,
                $capabilityKey,
                'COMPLETE',
                'Required features complete; integration/release checks clean; capability acceptance criteria passed in Domain QA.',
            );
        }
    }

    private function assertMandatoryCapabilitiesComplete(string $domainId): void
    {
        foreach ($this->domains->capabilities($domainId) as $capability) {
            if (!(bool) ($capability['required'] ?? false)) continue;
            if (($capability['status'] ?? null) !== 'COMPLETE') {
                throw new RuntimeException('Domain release requires mandatory capability COMPLETE: '.$capability['capability_key']);
            }
        }
    }

    /** @param list<array<string,mixed>> $expected @param list<array<string,mixed>> $actual */
    private function assertDomainAcceptanceCoverage(array $expected, array $actual): void
    {
        $expectedIds = [];
        foreach ($expected as $criterion) {
            if (!is_array($criterion)) continue;
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id !== '') $expectedIds[$id] = $criterion;
        }
        if ($expectedIds === []) throw new RuntimeException('Domain QA PASS requires authoritative Domain Acceptance Criteria.');

        $actualById = [];
        foreach ($actual as $criterion) {
            if (!is_array($criterion)) continue;
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id !== '') $actualById[$id] = $criterion;
        }

        $missing = array_diff_key($expectedIds, $actualById);
        $unexpected = array_diff_key($actualById, $expectedIds);
        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException(sprintf(
                'Domain QA acceptance-criteria coverage mismatch. Missing: %s; unexpected: %s.',
                implode(', ', array_keys($missing)) ?: 'none',
                implode(', ', array_keys($unexpected)) ?: 'none',
            ));
        }

        foreach ($expectedIds as $id => $criterion) {
            $result = $actualById[$id];
            $blocking = !array_key_exists('blocking', $criterion) || ($criterion['blocking'] ?? true) === true;
            if ($blocking && ($result['status'] ?? null) !== 'PASS') {
                throw new RuntimeException('Domain release requires PASS for blocking Acceptance Criterion '.$id.'.');
            }
            if ($blocking && !$this->meaningfulEvidence($result['evidence'] ?? null)) {
                throw new RuntimeException('Domain release requires evidence for blocking Acceptance Criterion '.$id.'.');
            }
        }
    }

    private function meaningfulEvidence(mixed $value): bool
    {
        if (is_string($value)) return trim($value) !== '';
        if (is_array($value)) return $value !== [];
        return is_scalar($value) && $value !== null;
    }

    private function requiredArtifact(string $domainId, EngineeringDomainArtifactType $type): array
    {
        $artifact = $this->domains->latestArtifact($domainId, $type->value);
        if ($artifact === null) throw new RuntimeException('Domain release missing required artifact '.$type->value.'.');
        return $artifact;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function view(string $domainId, array $extra = []): array
    {
        return array_merge([
            'domain' => $this->domains->domain($domainId),
            'features' => $this->domains->features($domainId),
            'artifacts' => $this->domains->artifacts($domainId),
        ], $extra);
    }
}
