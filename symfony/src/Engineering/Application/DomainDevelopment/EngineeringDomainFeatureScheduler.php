<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use App\Engineering\Domain\Workflow\EngineeringId;
use RuntimeException;

final readonly class EngineeringDomainFeatureScheduler
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringOrchestrator $engineering,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringDomainContextBuilder $context,
        private EngineeringDomainDriftDetector $drift,
        private FeatureDependencyGraph $graph = new FeatureDependencyGraph(),
    ) {}

    /** @return array<string,mixed> */
    public function tick(string $domainId, string $organizationId, string $correlationId): array
    {
        $domain = $this->domains->domain($domainId);
        if (($domain['organization_id'] ?? null) !== $organizationId) throw new RuntimeException('Engineering domain initiative does not belong to the current organization.');
        if (!in_array($domain['status'], [
            EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value,
            EngineeringDomainStatus::IMPLEMENTATION->value,
            EngineeringDomainStatus::INTEGRATION->value,
        ], true)) {
            throw new RuntimeException('Domain scheduler cannot run from status '.$domain['status'].'.');
        }

        $this->ensureIntegrationBranch($domain);
        $this->syncLinkedFeatures($domainId);
        $drift = $this->drift->refresh($domainId);
        $domainFeatures = $this->domains->features($domainId);
        $dependencies = $this->domains->dependencies($domainId);
        $this->graph->assertValid(
            array_map(static fn (array $feature): array => ['key' => $feature['feature_key']], $domainFeatures),
            $dependencies,
        );

        $running = count(array_filter($domainFeatures, static fn (array $feature): bool =>
            ($feature['engineering_feature_id'] ?? null) !== null
            && in_array($feature['status'], [
                EngineeringDomainFeatureStatus::RUNNING->value,
                EngineeringDomainFeatureStatus::WAITING->value,
            ], true)
        ));
        $capacity = max(0, (int) $domain['max_parallel_features'] - $running);
        $readyKeys = $this->graph->ready($domainFeatures, $dependencies);
        $byKey = [];
        foreach ($domainFeatures as $feature) $byKey[(string) $feature['feature_key']] = $feature;
        usort($readyKeys, function (string $a, string $b) use ($byKey): int {
            $rank = ['FOUNDATION' => 0, 'CORE' => 1, 'INTEGRATION' => 2, 'APPLICATION' => 3, 'UI' => 4, 'INFRASTRUCTURE' => 5];
            $fa = $byKey[$a]; $fb = $byKey[$b];
            $kind = ($rank[$fa['kind']] ?? 99) <=> ($rank[$fb['kind']] ?? 99);
            return $kind !== 0 ? $kind : strcmp((string) $fa['priority'], (string) $fb['priority']);
        });

        $scheduled = [];
        foreach ($readyKeys as $featureKey) {
            if ($capacity <= 0) break;
            $feature = $byKey[$featureKey] ?? null;
            if (!is_array($feature) || ($feature['engineering_feature_id'] ?? null) !== null) continue;
            $paths = array_values(array_unique(array_merge(
                is_array($feature['owned_paths'] ?? null) ? $feature['owned_paths'] : [],
                is_array($feature['shared_paths'] ?? null) ? $feature['shared_paths'] : [],
            )));
            if (!$this->domains->reservePaths($domainId, $featureKey, $paths)) {
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::WAITING->value, 'Waiting for conflicting path reservation.');
                continue;
            }

            try {
                $context = $this->context->forFeature($domainId, $featureKey);
                $architectureVersion = (int) ($context['architecture_version'] ?? 0);
                if ($architectureVersion <= 0) throw new RuntimeException('Domain feature cannot start without versioned Domain Architecture.');
                $contractSnapshot = $this->context->contractSnapshot($domainId, $featureKey);
                $engineeringFeatureId = $this->engineering->create(
                    new EngineeringRequest(
                        requestId: EngineeringId::generate(),
                        description: $this->featureDescription($feature, $context),
                        title: '['.$domain['domain_key'].'] '.$feature['title'],
                        sourceType: 'domain_runtime',
                        sourceReference: $domainId.':'.$featureKey,
                        priority: (string) $feature['priority'],
                        metadata: [
                            'engineering_mode' => 'DOMAIN_FEATURE',
                            'domain_id' => $domainId,
                            'domain_key' => $domain['domain_key'],
                            'domain_feature_key' => $featureKey,
                            'domain_feature_kind' => $feature['kind'],
                            'risk' => $feature['risk'],
                            'architecture_version' => $architectureVersion,
                        ],
                        constraints: [
                            'Follow DOMAIN_CONTEXT_PACK Architecture Constitution.',
                            'Do not change public contracts without Principal Architect revalidation.',
                            'Respect owned/shared/forbidden path policy.',
                            'Do not perform business operations of the target domain.',
                        ],
                        attachments: [],
                        previousContext: [['domain_development' => $context]],
                    ),
                    $organizationId,
                    'domain-runtime:'.$domainId,
                );
                $this->domains->linkEngineeringFeature($domainId, $featureKey, $engineeringFeatureId, $architectureVersion, $contractSnapshot);
                $this->engineering->queue($engineeringFeatureId, $organizationId, mb_substr($correlationId.':'.$featureKey, 0, 128));
                $scheduled[] = ['feature_key' => $featureKey, 'engineering_feature_id' => $engineeringFeatureId];
                --$capacity;
            } catch (\Throwable $error) {
                $this->domains->releasePaths($domainId, $featureKey);
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::BLOCKED->value, $error->getMessage());
            }
        }

        $this->syncLinkedFeatures($domainId);
        $domainFeatures = $this->domains->features($domainId);
        $required = array_filter($domainFeatures, static fn (array $feature): bool => (bool) $feature['required']);
        $allRequiredComplete = $required !== [] && array_reduce(
            $required,
            static fn (bool $carry, array $feature): bool => $carry && $feature['status'] === EngineeringDomainFeatureStatus::COMPLETED->value,
            true,
        );
        $this->domains->updateStatus(
            $domainId,
            $allRequiredComplete ? EngineeringDomainStatus::INTEGRATION->value : EngineeringDomainStatus::IMPLEMENTATION->value,
        );

        return [
            'domain_id' => $domainId,
            'scheduled' => $scheduled,
            'drift' => $drift,
            'running' => count(array_filter($domainFeatures, static fn (array $feature): bool => $feature['status'] === EngineeringDomainFeatureStatus::RUNNING->value)),
            'required_complete' => $allRequiredComplete,
            'features' => $domainFeatures,
        ];
    }

    /** @param array<string,mixed> $domain */
    private function ensureIntegrationBranch(array $domain): void
    {
        if (!$this->repository->available()) {
            throw new RuntimeException('Domain scheduler requires configured Engineering repository access.');
        }
        $target = trim((string) ($domain['target_branch'] ?? ''));
        if ($target === '') throw new RuntimeException('Domain target branch is required.');
        $base = $this->repository->configuredBaseBranch();
        if ($target === $base) return;

        try {
            $this->repository->currentBaseRevision($target);
            return;
        } catch (\Throwable) {
            $this->repository->ensureBranch($target, $this->repository->currentBaseRevision());
        }
    }

    private function syncLinkedFeatures(string $domainId): void
    {
        foreach ($this->domains->features($domainId) as $domainFeature) {
            $engineeringFeatureId = $domainFeature['engineering_feature_id'] ?? null;
            if (!is_string($engineeringFeatureId) || $engineeringFeatureId === '') continue;
            try {
                $feature = $this->features->view($engineeringFeatureId);
            } catch (\Throwable) {
                continue;
            }
            $status = strtoupper((string) ($feature['status'] ?? ''));
            if ($status === 'DONE') {
                $this->domains->updateFeatureStatus($domainId, (string) $domainFeature['feature_key'], EngineeringDomainFeatureStatus::COMPLETED->value);
                $this->domains->releasePaths($domainId, (string) $domainFeature['feature_key']);
            } elseif ($status === 'FAILED') {
                $this->domains->updateFeatureStatus($domainId, (string) $domainFeature['feature_key'], EngineeringDomainFeatureStatus::FAILED->value, 'Child Engineering workflow failed.');
                $this->domains->releasePaths($domainId, (string) $domainFeature['feature_key']);
            } elseif ($status === 'CANCELLED') {
                $this->domains->updateFeatureStatus($domainId, (string) $domainFeature['feature_key'], EngineeringDomainFeatureStatus::CANCELLED->value, 'Child Engineering workflow cancelled.');
                $this->domains->releasePaths($domainId, (string) $domainFeature['feature_key']);
            } elseif (!in_array($domainFeature['status'], [
                EngineeringDomainFeatureStatus::BLOCKED->value,
                EngineeringDomainFeatureStatus::STALE->value,
                EngineeringDomainFeatureStatus::REVALIDATION_REQUIRED->value,
            ], true)) {
                $this->domains->updateFeatureStatus($domainId, (string) $domainFeature['feature_key'], EngineeringDomainFeatureStatus::RUNNING->value);
            }
        }
    }

    /** @param array<string,mixed> $feature @param array<string,mixed> $context */
    private function featureDescription(array $feature, array $context): string
    {
        return trim((string) $feature['description'])."\n\n"
            ."This feature is one implementation unit of Domain ".$context['domain_name']." (".$context['domain_key'].").\n"
            ."Feature key: ".$feature['feature_key']."\n"
            ."Kind: ".$feature['kind']."; risk: ".$feature['risk']."\n"
            ."Acceptance criteria:\n".json_encode($feature['acceptance_criteria'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
