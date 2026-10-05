<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

interface EngineeringDomainStoreInterface
{
    public function create(
        string $id,
        string $organizationId,
        string $domainKey,
        string $name,
        string $masterSpecification,
        string $targetRepository,
        string $targetBranch,
        string $createdBy,
        int $maxParallelFeatures = 3,
    ): void;

    /** @return array<string,mixed> */
    public function domain(string $id): array;

    /** @return list<array<string,mixed>> */
    public function domainsForOrganization(string $organizationId, int $limit = 50): array;

    public function updateStatus(string $id, string $status, ?string $reason = null): void;

    /** @return array<string,mixed> */
    public function saveArtifact(string $domainId, string $type, array $content, string $createdBy): array;

    /** @return array<string,mixed>|null */
    public function latestArtifact(string $domainId, string $type): ?array;

    /** @return list<array<string,mixed>> */
    public function artifacts(string $domainId): array;

    /** @param list<array<string,mixed>> $capabilities @param list<array<string,mixed>> $features @param list<array<string,mixed>> $dependencies */
    public function replacePlan(string $domainId, array $capabilities, array $features, array $dependencies): void;

    /** @return list<array<string,mixed>> */
    public function capabilities(string $domainId): array;

    /** @return list<array<string,mixed>> */
    public function features(string $domainId): array;

    /** @return array<string,mixed> */
    public function feature(string $domainId, string $featureKey): array;

    /** @return list<array<string,mixed>> */
    public function dependencies(string $domainId): array;

    public function linkEngineeringFeature(
        string $domainId,
        string $featureKey,
        string $engineeringFeatureId,
        int $architectureVersion,
        array $contractSnapshot,
    ): void;

    public function updateFeatureStatus(string $domainId, string $featureKey, string $status, ?string $reason = null): void;

    /** @param list<array<string,mixed>> $contracts */
    public function replaceContracts(string $domainId, array $contracts): void;

    /** @return list<array<string,mixed>> */
    public function contracts(string $domainId): array;

    /** @param list<array<string,mixed>> $events */
    public function replaceEvents(string $domainId, array $events): void;

    /** @return list<array<string,mixed>> */
    public function events(string $domainId): array;

    /** @param list<string> $paths */
    public function reservePaths(string $domainId, string $featureKey, array $paths): bool;

    public function releasePaths(string $domainId, string $featureKey): void;

    /** @return list<array<string,mixed>> */
    public function pathReservations(string $domainId): array;

    public function recordAgentRun(
        string $domainId,
        string $role,
        string $status,
        string $correlationId,
        ?string $provider,
        ?string $model,
        array $usage,
        ?string $error,
    ): void;

    /** @return list<array<string,mixed>> */
    public function agentRuns(string $domainId): array;
}
