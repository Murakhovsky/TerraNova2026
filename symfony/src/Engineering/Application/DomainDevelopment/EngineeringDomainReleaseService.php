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

        $this->domains->updateStatus($domainId, EngineeringDomainStatus::DOMAIN_QA->value);
        $featureEvidence = [];
        foreach ($features as $feature) {
            $engineeringFeatureId = $feature['engineering_feature_id'] ?? null;
            if (!is_string($engineeringFeatureId) || $engineeringFeatureId === '') continue;
            $status = $this->engineering->status($engineeringFeatureId);
            $qa = $status['artifacts']['QA_REPORT']['content'] ?? null;
            $review = $status['artifacts']['REVIEW_REPORT']['content'] ?? null;
            $development = $status['artifacts']['DEVELOPMENT_RESULT']['content'] ?? null;
            $final = $status['artifacts']['FINAL_REPORT']['content'] ?? null;
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
            if (($ci['failed'] ?? 0) > 0 || ($ci['state'] ?? null) === 'FAILED') {
                $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Integrated repository revision has failed CI.');
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

        $report = array_merge($result, [
            'tested_revision' => $repositoryRevision,
            'feature_evidence' => $featureEvidence,
            'ci' => $ci,
            'architecture_version' => (int) $architecture['version'],
            'architecture_hash' => $architecture['content_hash'],
        ]);
        $this->domains->saveArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_QA_REPORT->value, $report, AgentRole::QA_EXECUTOR->value);

        $qaStatus = (string) ($result['status'] ?? '');
        if ($qaStatus !== 'PASS') {
            $state = $qaStatus === 'FAIL' ? EngineeringDomainStatus::FAILED->value : EngineeringDomainStatus::BLOCKED->value;
            $this->domains->updateStatus($domainId, $state, 'Domain QA returned '.$qaStatus.'.');
            return $this->view($domainId, ['qa_status' => $qaStatus]);
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
            ],
            $correlationId.':integration-release',
        );
        $this->domains->saveArtifact($domainId, 'DOMAIN_INTEGRATION_RELEASE_REPORT', $integrationRelease, AgentRole::INTEGRATION_RELEASE->value);

        $integrationStatus = (string) ($integrationRelease['status'] ?? '');
        if ($integrationStatus !== 'RELEASE_READY') {
            $state = $integrationStatus === 'FAILED'
                ? EngineeringDomainStatus::FAILED->value
                : EngineeringDomainStatus::BLOCKED->value;
            $this->domains->updateStatus($domainId, $state, 'Integration & Release Agent returned '.$integrationStatus.'.');
            return $this->view($domainId, ['integration_release' => $integrationRelease]);
        }

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
        $this->domains->updateStatus($domainId, EngineeringDomainStatus::RELEASE_READY->value);

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
            'contracts' => $this->domains->contracts((string) $domain['id']),
            'events' => $this->domains->events((string) $domain['id']),
            'migration_plan' => $this->domains->latestArtifact((string) $domain['id'], EngineeringDomainArtifactType::MIGRATION_PLAN->value)['content'] ?? [],
            'feature_flags' => $architecture['content']['feature_flags'] ?? [],
            'qa_result' => [
                'status' => $qa['status'] ?? null,
                'tested_revision' => $qa['tested_revision'] ?? null,
                'acceptance_criteria' => $qa['acceptance_criteria'] ?? [],
                'defects' => $qa['defects'] ?? [],
            ],
            'integration_release' => $integrationRelease,
            'ci' => $ci,
            'rollback_plan' => $architecture['content']['migration_strategy'] ?? null,
            'known_limitations' => $qa['known_limitations'] ?? [],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
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
