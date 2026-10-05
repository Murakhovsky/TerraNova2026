<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
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

    private function assertTenant(string $domainId, string $organizationId): void
    {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        }
    }
}
