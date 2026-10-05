<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Domain\DomainDevelopment\DomainDependencyGraph;
use App\Engineering\Domain\DomainDevelopment\DomainDependencyType;
use App\Engineering\Domain\DomainDevelopment\DomainDevelopmentStatus;
use App\Engineering\Domain\DomainDevelopment\DomainFeatureKind;
use App\Engineering\Domain\DomainDevelopment\DomainReleaseReadinessEvaluator;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Infrastructure\Persistence\Doctrine\DoctrineDomainDevelopmentStore;
use InvalidArgumentException;
use RuntimeException;

final readonly class DomainDevelopmentCoordinator
{
    public function __construct(
        private DoctrineDomainDevelopmentStore $domains,
        private DomainReleaseReadinessEvaluator $readiness = new DomainReleaseReadinessEvaluator(),
    ) {}

    public function create(
        string $organizationId,
        string $domainKey,
        string $name,
        string $description,
        array $masterSpecification,
        string $version = '1.0',
        string $repository = 'Murakhovsky/TerraNova2026',
        string $targetBranch = 'main',
        ?string $createdBy = null,
    ): string {
        $organizationId = trim($organizationId);
        $domainKey = strtolower(trim($domainKey));
        $name = trim($name);
        if ($organizationId === '' || $domainKey === '' || $name === '') {
            throw new InvalidArgumentException('Organization, domain key and name are required.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,126}[a-z0-9]$/', $domainKey)) {
            throw new InvalidArgumentException('Domain key must be a stable lowercase identifier.');
        }
        if ($masterSpecification === []) throw new InvalidArgumentException('Master Domain Specification is required.');

        $id = EngineeringId::generate();
        $this->domains->createDomain(
            $id,
            $organizationId,
            $domainKey,
            $name,
            trim($description),
            $masterSpecification,
            trim($version) ?: '1.0',
            trim($repository),
            trim($targetBranch) ?: 'main',
            $createdBy,
        );
        $this->domains->createArtifact($id, 'DOMAIN_SPECIFICATION', $masterSpecification, 0, 'ENGINEERING_MANAGER');
        $this->domains->updateDomainStatus($id, DomainDevelopmentStatus::ANALYSIS, $createdBy, 'Master Domain Specification accepted.');
        return $id;
    }

    public function addCapability(
        string $domainId,
        string $key,
        string $name,
        string $description,
        string $kind = 'CORE',
        bool $required = true,
    ): string {
        $domain = $this->domains->domain($domainId);
        $id = EngineeringId::generate();
        $this->domains->addCapability($id, $domainId, trim($key), trim($name), trim($description), strtoupper(trim($kind)), $required);
        if (($domain['status'] ?? null) === DomainDevelopmentStatus::ANALYSIS->value) {
            $this->domains->updateDomainStatus($domainId, DomainDevelopmentStatus::DECOMPOSITION);
        }
        return $id;
    }

    public function addFeature(
        string $domainId,
        ?string $capabilityId,
        string $featureKey,
        string $title,
        string $description,
        DomainFeatureKind $kind,
        string $risk = 'MEDIUM',
        string $priority = 'P2',
        bool $required = true,
        array $ownedPaths = [],
        array $sharedPaths = [],
        array $forbiddenPaths = [],
    ): string {
        $this->domains->domain($domainId);
        $priority = strtoupper(trim($priority));
        $risk = strtoupper(trim($risk));
        if (!in_array($priority, ['P0','P1','P2','P3'], true)) throw new InvalidArgumentException('Domain feature priority must be P0-P3.');
        if (!in_array($risk, ['LOW','MEDIUM','HIGH','CRITICAL'], true)) throw new InvalidArgumentException('Domain feature risk must be LOW, MEDIUM, HIGH or CRITICAL.');
        $id = EngineeringId::generate();
        $this->domains->addFeature(
            $id, $domainId, $capabilityId, trim($featureKey), trim($title), trim($description),
            $kind->value, $risk, $priority, $required, $ownedPaths, $sharedPaths, $forbiddenPaths,
        );
        return $id;
    }

    public function addDependency(
        string $domainId,
        string $featureId,
        string $dependsOnFeatureId,
        DomainDependencyType $type = DomainDependencyType::REQUIRES,
    ): string {
        $id = EngineeringId::generate();
        $dependencies = $this->domains->dependencies($domainId);
        $dependencies[] = [
            'feature_id' => $featureId,
            'depends_on_feature_id' => $dependsOnFeatureId,
            'dependency_type' => $type->value,
        ];
        new DomainDependencyGraph($this->domains->features($domainId), $dependencies);
        $this->domains->addDependency($id, $domainId, $featureId, $dependsOnFeatureId, $type->value);
        return $id;
    }

    public function approveArchitecture(
        string $domainId,
        array $architecture,
        array $constitution,
        array $domainAcceptanceCriteria,
        ?string $actor = null,
    ): int {
        if ($architecture === [] || $constitution === []) {
            throw new InvalidArgumentException('Domain Architecture and Architecture Constitution are required.');
        }
        new DomainDependencyGraph($this->domains->features($domainId), $this->domains->dependencies($domainId));
        return $this->domains->setArchitecture($domainId, $architecture, $constitution, $domainAcceptanceCriteria, $actor);
    }

    public function registerContract(
        string $domainId,
        string $contractKey,
        string $type,
        string $version,
        string $compatibility,
        ?string $producerFeatureId,
        array $consumers,
        array $schema,
    ): void {
        $type = strtoupper(trim($type));
        $compatibility = strtoupper(trim($compatibility));
        if (!in_array($type, ['DOMAIN_INTERFACE','APPLICATION_INTERFACE','API_CONTRACT','EVENT_CONTRACT','DATABASE_CONTRACT','INTEGRATION_CONTRACT','PERMISSION_CONTRACT'], true)) {
            throw new InvalidArgumentException('Unsupported Engineering Domain contract type.');
        }
        if (!in_array($compatibility, ['BACKWARD_COMPATIBLE','BREAKING','DEPRECATED'], true)) {
            throw new InvalidArgumentException('Unsupported contract compatibility mode.');
        }

        $previous = $this->domains->contractByKey($domainId, $contractKey);
        $this->domains->registerContract(
            EngineeringId::generate(),
            $domainId,
            trim($contractKey),
            $type,
            trim($version),
            $compatibility,
            $producerFeatureId,
            $consumers,
            $schema,
        );

        if ($previous !== null && $compatibility === 'BREAKING' && (string) $previous['version'] !== $version) {
            $consumerIds = array_values(array_filter(array_map(
                static fn (mixed $consumer): ?string => is_string($consumer) ? $consumer : (is_array($consumer) ? (string) ($consumer['feature_id'] ?? '') : null),
                $consumers,
            )));
            $this->domains->markConsumerFeaturesStale($domainId, $consumerIds);
            $this->domains->audit($domainId, 'CONTRACT_REVALIDATION_REQUIRED', [
                'contract_key' => $contractKey,
                'from_version' => $previous['version'],
                'to_version' => $version,
                'consumers' => $consumerIds,
            ]);
        }
    }

    public function recordDomainQa(string $domainId, string $status, array $report, ?string $actor = null): void
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, ['PASS','FAIL','BLOCKED','HUMAN_TEST_REQUIRED'], true)) {
            throw new InvalidArgumentException('Unsupported Domain QA status.');
        }

        if ($status === 'PASS') {
            foreach ($this->domains->features($domainId) as $feature) {
                if ((bool) ($feature['required'] ?? true) && ($feature['status'] ?? null) !== 'COMPLETED') {
                    throw new RuntimeException('Domain QA cannot PASS before all required features are COMPLETED.');
                }
            }
        }
        $this->domains->recordDomainQa($domainId, $status, $report, $actor);
    }

    /** @return array{ready:bool,blockers:list<string>} */
    public function releaseReadiness(string $domainId): array
    {
        return $this->readiness->evaluate(
            $this->domains->domain($domainId),
            $this->domains->features($domainId),
            $this->domains->contracts($domainId),
        );
    }

    /** @return array<string,mixed> */
    public function markReleaseReady(string $domainId, ?string $actor = null): array
    {
        $readiness = $this->releaseReadiness($domainId);
        if (!$readiness['ready']) {
            throw new RuntimeException('Domain is not release-ready: '.implode(' ', $readiness['blockers']));
        }

        $domain = $this->domains->domain($domainId);
        $features = $this->domains->features($domainId);
        $manifest = [
            'domain' => ['id' => $domain['id'], 'key' => $domain['domain_key'], 'version' => $domain['version']],
            'architecture_version' => $domain['architecture_version'],
            'included_features' => array_map(static fn (array $feature): array => [
                'feature_key' => $feature['feature_key'],
                'engineering_feature_id' => $feature['engineering_feature_id'],
                'architecture_version' => $feature['architecture_version'],
            ], $features),
            'contracts' => $this->domains->contracts($domainId),
            'qa_status' => $domain['qa_status'],
            'known_limitations' => [],
            'rollback_plan' => $domain['architecture_json']['migration_strategy']['rollback'] ?? null,
        ];
        $this->domains->setReleaseReady($domainId, $manifest, $actor);
        return $manifest;
    }
}
