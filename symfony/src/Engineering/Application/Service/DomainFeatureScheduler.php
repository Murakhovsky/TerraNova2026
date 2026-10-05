<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Domain\DomainDevelopment\DomainDependencyGraph;
use App\Engineering\Domain\DomainDevelopment\DomainDevelopmentStatus;
use App\Engineering\Domain\DomainDevelopment\DomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\DomainPathReservationPolicy;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Infrastructure\Persistence\Doctrine\DoctrineDomainDevelopmentStore;
use RuntimeException;
use Throwable;

final readonly class DomainFeatureScheduler
{
    public function __construct(
        private DoctrineDomainDevelopmentStore $domains,
        private EngineeringOrchestrator $engineering,
        private EngineeringStatusService $engineeringStatus,
        private DomainFeatureContextBuilder $contexts,
        private DomainPathReservationPolicy $paths = new DomainPathReservationPolicy(),
    ) {}

    /** @return array<string,mixed> */
    public function tick(
        string $domainId,
        string $organizationId,
        string $correlationId,
        int $maxParallel = 3,
    ): array {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering domain does not belong to current organization.');
        }
        if ((int) ($domain['architecture_version'] ?? 0) < 1) {
            throw new RuntimeException('Domain Architecture must be approved before feature scheduling.');
        }

        $this->reconcile($domain);
        $features = $this->domains->features($domainId);
        $dependencies = $this->domains->dependencies($domainId);
        $graph = new DomainDependencyGraph($features, $dependencies);

        $required = array_values(array_filter($features, static fn (array $feature): bool => (bool) ($feature['required'] ?? true)));
        if ($required !== [] && count(array_filter($required, static fn (array $feature): bool => ($feature['status'] ?? null) === DomainFeatureStatus::COMPLETED->value)) === count($required)) {
            $this->domains->updateDomainStatus($domainId, DomainDevelopmentStatus::INTEGRATION, reason: 'All required feature workflows completed.');
            return ['scheduled' => [], 'state' => DomainDevelopmentStatus::INTEGRATION->value, 'ready' => [], 'blocked_by_path' => []];
        }

        $active = array_values(array_filter(
            $features,
            static fn (array $feature): bool => DomainFeatureStatus::from((string) $feature['status'])->active(),
        ));
        $readyIds = $graph->readyFeatureIds();
        $byId = [];
        foreach ($features as $feature) $byId[(string) $feature['id']] = $feature;

        $scheduled = [];
        $blockedByPath = [];
        foreach ($readyIds as $featureId) {
            if (count($scheduled) >= max(1, min(20, $maxParallel))) break;
            $feature = $byId[$featureId];
            if ($this->paths->conflicts($feature, $active)) {
                $blockedByPath[] = $featureId;
                continue;
            }
            if (!$this->domains->claimFeature($featureId, (int) $domain['architecture_version'])) continue;

            try {
                $dependencyIds = $graph->directDependencyIds($featureId);
                $context = $this->contexts->build($domain, $feature, $dependencyIds);
                $engineeringFeatureId = trim((string) ($feature['engineering_feature_id'] ?? ''));
                if ($engineeringFeatureId === '') {
                    $engineeringFeatureId = $this->engineering->create(
                        new EngineeringRequest(
                            requestId: EngineeringId::generate(),
                            description: (string) $feature['description'],
                            title: (string) $feature['title'],
                            sourceType: 'domain_runtime',
                            sourceReference: $domainId.':'.(string) $feature['feature_key'],
                            priority: (string) $feature['priority'],
                            metadata: [
                                'domain_id' => $domainId,
                                'domain_key' => $domain['domain_key'],
                                'domain_version' => $domain['version'],
                                'architecture_version' => $domain['architecture_version'],
                                'domain_feature_id' => $featureId,
                                'domain_feature_key' => $feature['feature_key'],
                                'feature_kind' => $feature['kind'],
                                'risk' => $feature['risk'],
                            ],
                            constraints: $context,
                            attachments: [],
                            previousContext: [],
                        ),
                        $organizationId,
                        'domain-runtime:'.$domainId,
                    );
                    $this->domains->bindEngineeringFeature($featureId, $engineeringFeatureId);
                }

                $this->engineering->queue(
                    $engineeringFeatureId,
                    $organizationId,
                    mb_substr(rtrim($correlationId, ':').':domain:'.$domainId.':feature:'.$featureId, 0, 128),
                );
                $this->domains->updateFeatureStatus($featureId, DomainFeatureStatus::RUNNING);
                $this->domains->audit($domainId, 'FEATURE_WORKFLOW_QUEUED', [
                    'domain_feature_id' => $featureId,
                    'engineering_feature_id' => $engineeringFeatureId,
                ], 'DOMAIN_FEATURE_SCHEDULER');

                $scheduled[] = [
                    'domain_feature_id' => $featureId,
                    'feature_key' => $feature['feature_key'],
                    'engineering_feature_id' => $engineeringFeatureId,
                ];
                $active[] = array_merge($feature, ['status' => DomainFeatureStatus::RUNNING->value]);
            } catch (Throwable $error) {
                $this->domains->updateFeatureStatus($featureId, DomainFeatureStatus::BLOCKED, $error->getMessage());
                $this->domains->audit($domainId, 'FEATURE_SCHEDULING_FAILED', [
                    'domain_feature_id' => $featureId,
                    'error' => $error->getMessage(),
                ], 'DOMAIN_FEATURE_SCHEDULER');
            }
        }

        if ($scheduled !== []) {
            $this->domains->updateDomainStatus($domainId, DomainDevelopmentStatus::IMPLEMENTATION, reason: 'Dependency-ready feature workflows scheduled.');
        }

        return [
            'scheduled' => $scheduled,
            'state' => $this->domains->domain($domainId)['status'],
            'ready' => $readyIds,
            'blocked_by_path' => $blockedByPath,
        ];
    }

    private function reconcile(array $domain): void
    {
        foreach ($this->domains->features((string) $domain['id']) as $feature) {
            $engineeringFeatureId = trim((string) ($feature['engineering_feature_id'] ?? ''));
            if ($engineeringFeatureId === '') continue;

            try {
                $status = $this->engineeringStatus->status($engineeringFeatureId);
                $state = (string) ($status['workflow']['state'] ?? $status['feature']['status'] ?? '');
                $mapped = match ($state) {
                    'DONE' => ((int) ($feature['architecture_version'] ?? 0) === (int) ($domain['architecture_version'] ?? 0))
                        ? DomainFeatureStatus::COMPLETED
                        : DomainFeatureStatus::STALE,
                    'READY_FOR_HUMAN_APPROVAL', 'HUMAN_DECISION_REQUIRED' => DomainFeatureStatus::WAITING_HUMAN,
                    'BLOCKED', 'ESCALATED' => DomainFeatureStatus::BLOCKED,
                    'FAILED', 'CANCELLED' => DomainFeatureStatus::FAILED,
                    default => DomainFeatureStatus::RUNNING,
                };
                if (($feature['status'] ?? null) !== $mapped->value) {
                    $this->domains->updateFeatureStatus((string) $feature['id'], $mapped);
                }
            } catch (Throwable $error) {
                $this->domains->audit((string) $domain['id'], 'FEATURE_RECONCILIATION_WARNING', [
                    'domain_feature_id' => $feature['id'],
                    'engineering_feature_id' => $engineeringFeatureId,
                    'error' => $error->getMessage(),
                ], 'DOMAIN_FEATURE_SCHEDULER');
            }
        }
    }
}
