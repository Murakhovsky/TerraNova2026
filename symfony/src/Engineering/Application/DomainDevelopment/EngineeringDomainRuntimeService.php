<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use InvalidArgumentException;
use RuntimeException;

final readonly class EngineeringDomainRuntimeService
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainPlanner $planner,
        private EngineeringDomainFeatureScheduler $scheduler,
        private EngineeringDomainReleaseService $release,
        private EngineeringDomainHumanGateService $humanGates,
        private EngineeringRepositoryGatewayInterface $repository,
        private int $defaultMaxParallelFeatures = 3,
        private int $defaultMaxParallelDevelopers = 2,
        private int $defaultMaxParallelReviews = 2,
        private int $defaultMaxParallelQa = 2,
    ) {}

    public function create(
        string $organizationId,
        string $domainKey,
        string $name,
        string $masterSpecification,
        string $targetRepository,
        string $targetBranch,
        string $createdBy,
        int $maxParallelFeatures = 0,
        int $maxParallelDevelopers = 0,
        int $maxParallelReviews = 0,
        int $maxParallelQa = 0,
    ): string {
        if (trim($organizationId) === '') throw new InvalidArgumentException('Organization id is required.');
        $targetRepository = trim($targetRepository);
        if ($targetRepository === '') {
            $targetRepository = $this->repository->configuredRepository();
        }
        $maxParallelFeatures = $maxParallelFeatures > 0 ? $maxParallelFeatures : $this->defaultMaxParallelFeatures;
        $maxParallelDevelopers = $maxParallelDevelopers > 0 ? $maxParallelDevelopers : $this->defaultMaxParallelDevelopers;
        $maxParallelReviews = $maxParallelReviews > 0 ? $maxParallelReviews : $this->defaultMaxParallelReviews;
        $maxParallelQa = $maxParallelQa > 0 ? $maxParallelQa : $this->defaultMaxParallelQa;

        $id = EngineeringId::generate();
        $targetBranch = trim($targetBranch);
        if ($targetBranch === '') {
            $slug = strtolower(trim($domainKey));
            $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?: 'domain';
            $targetBranch = 'domain/'.trim($slug, '.-_');
        }
        $this->domains->create(
            $id,
            $organizationId,
            $domainKey,
            $name,
            $masterSpecification,
            $targetRepository,
            $targetBranch,
            $createdBy,
            $maxParallelFeatures,
            $maxParallelDevelopers,
            $maxParallelReviews,
            $maxParallelQa,
        );
        $this->domains->saveArtifact(
            $id,
            EngineeringDomainArtifactType::DOMAIN_FEATURE_FLAGS->value,
            [
                'DOMAIN_ENABLED' => false,
                'FEATURE_ENABLED' => [],
                'INTEGRATION_ENABLED' => false,
                'PRODUCTION_EXECUTION_ENABLED' => false,
            ],
            $createdBy,
        );
        $this->domains->recordRuntimeEvent(
            $id,
            $organizationId,
            EngineeringDomainRuntimeEventType::DOMAIN_CREATED->value,
            null,
            [
                'domain_key' => $domainKey,
                'name' => $name,
                'target_repository' => $targetRepository,
                'target_branch' => $targetBranch,
                'concurrency' => [
                    'features' => $maxParallelFeatures,
                    'developers' => $maxParallelDevelopers,
                    'reviews' => $maxParallelReviews,
                    'qa' => $maxParallelQa,
                ],
            ],
            'engineering-domain:create:'.$id,
            'domain-created:v1',
            actor: $createdBy,
            reason: 'Domain Initiative created from Master Specification.',
            result: EngineeringDomainStatus::DRAFT->value,
        );
        return $id;
    }

    /** @return array<string,mixed> */
    public function view(string $domainId, string $organizationId): array
    {
        $this->assertTenant($domainId, $organizationId);
        return $this->planner->view($domainId);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $organizationId, int $limit = 50): array
    {
        return $this->domains->domainsForOrganization($organizationId, $limit);
    }

    /** @return array<string,mixed> */
    public function plan(string $domainId, string $organizationId, string $correlationId): array
    {
        $this->assertTenant($domainId, $organizationId);
        return $this->planner->plan($domainId, $organizationId, $correlationId);
    }

    /** @return array<string,mixed> */
    public function tick(string $domainId, string $organizationId, string $correlationId): array
    {
        $this->assertTenant($domainId, $organizationId);
        return $this->scheduler->tick($domainId, $organizationId, $correlationId);
    }

    /** @return array<string,mixed> */
    public function verify(string $domainId, string $organizationId, string $correlationId): array
    {
        $this->assertTenant($domainId, $organizationId);
        return $this->release->verify($domainId, $organizationId, $correlationId);
    }

    /** @return array<string,mixed> */
    public function approveRelease(string $domainId, string $organizationId, string $approvedBy): array
    {
        $this->assertTenant($domainId, $organizationId);
        return $this->release->approve($domainId, $approvedBy);
    }

    /** @return array<string,mixed> */
    public function answerHumanDecision(
        string $domainId,
        string $organizationId,
        string $decisionId,
        string $selectedOption,
        string $answeredBy,
        ?string $notes = null,
    ): array {
        $this->assertTenant($domainId, $organizationId);
        $decision = $this->humanGates->answer(
            $domainId,
            EngineeringId::assert($decisionId),
            $selectedOption,
            $answeredBy,
            $notes,
        );

        return [
            'decision' => $decision,
            'domain' => $this->domains->domain($domainId),
            'open_human_decisions' => $this->domains->openHumanDecisions($domainId),
        ];
    }

    /** @param array<string,mixed> $flags @return array<string,mixed> */
    public function updateFeatureFlags(string $domainId, string $organizationId, array $flags, string $updatedBy): array
    {
        $this->assertTenant($domainId, $organizationId);
        $domain = $this->domains->domain($domainId);
        $normalized = $this->normalizeFeatureFlags($flags);

        if ($normalized['PRODUCTION_EXECUTION_ENABLED'] === true
            && ($domain['status'] ?? null) !== EngineeringDomainStatus::COMPLETED->value) {
            throw new RuntimeException('Production execution can be enabled only after human-approved Domain completion.');
        }

        $artifact = $this->domains->saveArtifact(
            $domainId,
            EngineeringDomainArtifactType::DOMAIN_FEATURE_FLAGS->value,
            $normalized,
            $updatedBy,
        );

        return [
            'domain' => $domain,
            'feature_flags' => $artifact,
        ];
    }

    /** @param array<string,mixed> $flags @return array{DOMAIN_ENABLED:bool,FEATURE_ENABLED:array<string,bool>,INTEGRATION_ENABLED:bool,PRODUCTION_EXECUTION_ENABLED:bool} */
    private function normalizeFeatureFlags(array $flags): array
    {
        foreach (['DOMAIN_ENABLED','INTEGRATION_ENABLED','PRODUCTION_EXECUTION_ENABLED'] as $key) {
            if (!array_key_exists($key, $flags) || !is_bool($flags[$key])) {
                throw new InvalidArgumentException('Domain feature flag '.$key.' must be boolean.');
            }
        }
        $featureEnabled = $flags['FEATURE_ENABLED'] ?? null;
        if (!is_array($featureEnabled) || array_is_list($featureEnabled)) {
            throw new InvalidArgumentException('FEATURE_ENABLED must be a feature-key boolean map.');
        }
        $normalizedFeatures = [];
        foreach ($featureEnabled as $key => $enabled) {
            $key = trim((string) $key);
            if ($key === '' || !is_bool($enabled)) throw new InvalidArgumentException('FEATURE_ENABLED entries must be feature-key booleans.');
            $normalizedFeatures[$key] = $enabled;
        }
        ksort($normalizedFeatures);

        return [
            'DOMAIN_ENABLED' => $flags['DOMAIN_ENABLED'],
            'FEATURE_ENABLED' => $normalizedFeatures,
            'INTEGRATION_ENABLED' => $flags['INTEGRATION_ENABLED'],
            'PRODUCTION_EXECUTION_ENABLED' => $flags['PRODUCTION_EXECUTION_ENABLED'],
        ];
    }

    private function assertTenant(string $domainId, string $organizationId): void
    {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
    }
}
