<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Service\EngineeringCancelService;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainRuntimeEventType;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use App\Engineering\Domain\Workflow\EngineeringId;
use RuntimeException;

final readonly class EngineeringDomainFeatureScheduler
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringOrchestrator $engineering,
        private EngineeringCancelService $cancel,
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
        $this->syncLinkedFeatures($domainId, $organizationId, $correlationId);
        $drift = $this->drift->refresh($domainId);
        $domainFeatures = $this->domains->features($domainId);
        $domainArchitecture = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value);
        $domainArchitectureVersion = max(1, (int) ($domainArchitecture['version'] ?? 1));
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
        foreach ($readyKeys as $featureKey) {
            $feature = null;
            foreach ($domainFeatures as $candidate) {
                if (($candidate['feature_key'] ?? null) === $featureKey) {
                    $feature = $candidate;
                    break;
                }
            }
            $contractFingerprint = hash('sha256', json_encode(
                $this->context->contractSnapshot($domainId, $featureKey),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            $this->domains->recordRuntimeEvent(
                $domainId,
                $organizationId,
                EngineeringDomainRuntimeEventType::FEATURE_READY->value,
                $featureKey,
                [
                    'architecture_version' => $domainArchitectureVersion,
                    'priority' => $feature['priority'] ?? null,
                    'kind' => $feature['kind'] ?? null,
                    'domain_feature_status' => $feature['status'] ?? null,
                    'contract_fingerprint' => $contractFingerprint,
                ],
                $correlationId,
                'feature-ready:'.$featureKey.':architecture-v'.$domainArchitectureVersion.':contracts-'.$contractFingerprint,
            );
        }
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
            if (!is_array($feature)) continue;

            $isRevalidation = in_array((string) ($feature['status'] ?? ''), [
                EngineeringDomainFeatureStatus::STALE->value,
                EngineeringDomainFeatureStatus::REVALIDATION_REQUIRED->value,
            ], true);
            $previousEngineeringFeatureId = is_string($feature['engineering_feature_id'] ?? null)
                ? trim((string) $feature['engineering_feature_id'])
                : '';
            if (!$isRevalidation && $previousEngineeringFeatureId !== '') continue;

            if ($isRevalidation) {
                $this->domains->releasePaths($domainId, $featureKey);
                if ($previousEngineeringFeatureId !== '') {
                    try {
                        $previous = $this->features->view($previousEngineeringFeatureId);
                        $previousStatus = strtoupper((string) ($previous['status'] ?? ''));
                        if (!in_array($previousStatus, ['DONE','FAILED','CANCELLED'], true)) {
                            $this->cancel->cancel(
                                $previousEngineeringFeatureId,
                                'domain-runtime:'.$domainId,
                                'Superseded by Domain architecture or contract revalidation for '.$featureKey.'.',
                            );
                        }
                    } catch (\Throwable $error) {
                        $reason = 'Cannot supersede stale child workflow: '.$error->getMessage();
                        $this->domains->updateFeatureStatus(
                            $domainId,
                            $featureKey,
                            EngineeringDomainFeatureStatus::BLOCKED->value,
                            $reason,
                        );
                        $this->domains->recordRuntimeEvent(
                            $domainId,
                            $organizationId,
                            EngineeringDomainRuntimeEventType::FEATURE_BLOCKED->value,
                            $featureKey,
                            [
                                'engineering_feature_id' => $previousEngineeringFeatureId,
                                'reason' => $reason,
                                'phase' => 'REVALIDATION_SUPERSESSION',
                            ],
                            $correlationId,
                            'feature-blocked:'.$featureKey.':revalidation:'.hash('sha256', $reason),
                        );
                        continue;
                    }
                }
            }

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
                            'revalidation_of' => $isRevalidation && $previousEngineeringFeatureId !== '' ? $previousEngineeringFeatureId : null,
                            'revalidation_reason' => $isRevalidation ? ($feature['status_reason'] ?? 'Domain architecture/contract drift.') : null,
                        ],
                        constraints: [
                            'Follow DOMAIN_CONTEXT_PACK Architecture Constitution.',
                            'Do not change public contracts without Principal Architect revalidation.',
                            'Respect owned/shared/forbidden path policy.',
                            'Do not perform business operations of the target domain.',
                        ],
                        attachments: [],
                        previousContext: [[
                            'domain_development' => $context,
                            'revalidation' => $isRevalidation ? [
                                'previous_engineering_feature_id' => $previousEngineeringFeatureId !== '' ? $previousEngineeringFeatureId : null,
                                'reason' => $feature['status_reason'] ?? 'Domain architecture/contract drift.',
                            ] : null,
                        ]],
                    ),
                    $organizationId,
                    'domain-runtime:'.$domainId,
                );
                $this->domains->linkEngineeringFeature($domainId, $featureKey, $engineeringFeatureId, $architectureVersion, $contractSnapshot);
                $this->engineering->queue($engineeringFeatureId, $organizationId, mb_substr($correlationId.':'.$featureKey, 0, 128));
                $this->domains->recordRuntimeEvent(
                    $domainId,
                    $organizationId,
                    EngineeringDomainRuntimeEventType::FEATURE_STARTED->value,
                    $featureKey,
                    [
                        'engineering_feature_id' => $engineeringFeatureId,
                        'architecture_version' => $architectureVersion,
                        'revalidation' => $isRevalidation,
                        'revalidation_of' => $previousEngineeringFeatureId !== '' ? $previousEngineeringFeatureId : null,
                    ],
                    $correlationId,
                    'feature-started:'.$featureKey.':'.$engineeringFeatureId,
                );
                $scheduled[] = ['feature_key' => $featureKey, 'engineering_feature_id' => $engineeringFeatureId];
                --$capacity;
            } catch (\Throwable $error) {
                $this->domains->releasePaths($domainId, $featureKey);
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::BLOCKED->value, $error->getMessage());
                $this->domains->recordRuntimeEvent(
                    $domainId,
                    $organizationId,
                    EngineeringDomainRuntimeEventType::FEATURE_BLOCKED->value,
                    $featureKey,
                    ['reason' => $error->getMessage(), 'phase' => 'SCHEDULING'],
                    $correlationId,
                    'feature-blocked:'.$featureKey.':scheduling:'.hash('sha256', $error->getMessage()),
                );
            }
        }

        $this->syncLinkedFeatures($domainId, $organizationId, $correlationId);
        $domainFeatures = $this->domains->features($domainId);
        $required = array_filter($domainFeatures, static fn (array $feature): bool => (bool) $feature['required']);
        $allRequiredComplete = $required !== [] && array_reduce(
            $required,
            static fn (bool $carry, array $feature): bool => $carry && $feature['status'] === EngineeringDomainFeatureStatus::COMPLETED->value,
            true,
        );
        $nextDomainStatus = $allRequiredComplete ? EngineeringDomainStatus::INTEGRATION->value : EngineeringDomainStatus::IMPLEMENTATION->value;
        $this->domains->updateStatus($domainId, $nextDomainStatus);
        if ($allRequiredComplete) {
            $this->domains->recordRuntimeEvent(
                $domainId,
                $organizationId,
                EngineeringDomainRuntimeEventType::DOMAIN_INTEGRATION_STARTED->value,
                null,
                ['features' => count($domainFeatures), 'required_features' => count($required)],
                $correlationId,
                'domain-integration-started:architecture-v'.$domainArchitectureVersion,
            );
        }

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

    private function syncLinkedFeatures(string $domainId, string $organizationId, string $correlationId): void
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
            $featureKey = (string) $domainFeature['feature_key'];
            if ($status === 'DONE') {
                $wasComplete = ($domainFeature['status'] ?? null) === EngineeringDomainFeatureStatus::COMPLETED->value;
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::COMPLETED->value);
                $this->domains->releasePaths($domainId, $featureKey);
                if (!$wasComplete) {
                    $this->domains->recordRuntimeEvent(
                        $domainId,
                        $organizationId,
                        EngineeringDomainRuntimeEventType::FEATURE_COMPLETED->value,
                        $featureKey,
                        ['engineering_feature_id' => $engineeringFeatureId],
                        $correlationId,
                        'feature-completed:'.$featureKey.':'.$engineeringFeatureId,
                    );
                }
            } elseif ($status === 'FAILED') {
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::FAILED->value, 'Child Engineering workflow failed.');
                $this->domains->releasePaths($domainId, $featureKey);
                $this->domains->recordRuntimeEvent(
                    $domainId,
                    $organizationId,
                    EngineeringDomainRuntimeEventType::FEATURE_BLOCKED->value,
                    $featureKey,
                    ['engineering_feature_id' => $engineeringFeatureId, 'reason' => 'Child Engineering workflow failed.'],
                    $correlationId,
                    'feature-blocked:'.$featureKey.':failed:'.$engineeringFeatureId,
                );
            } elseif ($status === 'CANCELLED') {
                $this->domains->updateFeatureStatus($domainId, $featureKey, EngineeringDomainFeatureStatus::CANCELLED->value, 'Child Engineering workflow cancelled.');
                $this->domains->releasePaths($domainId, $featureKey);
                $this->domains->recordRuntimeEvent(
                    $domainId,
                    $organizationId,
                    EngineeringDomainRuntimeEventType::FEATURE_BLOCKED->value,
                    $featureKey,
                    ['engineering_feature_id' => $engineeringFeatureId, 'reason' => 'Child Engineering workflow cancelled.'],
                    $correlationId,
                    'feature-blocked:'.$featureKey.':cancelled:'.$engineeringFeatureId,
                );
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
