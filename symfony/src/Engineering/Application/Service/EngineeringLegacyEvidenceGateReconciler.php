<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use RuntimeException;

final readonly class EngineeringLegacyEvidenceGateReconciler
{
    public function __construct(
        private EngineeringHumanDecisionStoreInterface $decisions,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringExecutionJournal $journal,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
        private EngineeringArchitectEvidenceAuthorization $policy = new EngineeringArchitectEvidenceAuthorization(),
    ) {}

    public function reconcile(string $featureId, string $workflowId, string $correlationId): bool
    {
        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $correlationId): bool {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED
                || $workflow->resumeState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) return false;

            $open = $this->decisions->openForFeature($featureId);
            if (count($open) !== 1 || !is_array($open[0])) return false;
            $request = $open[0];
            if (($request['workflow_id'] ?? null) !== $workflowId
                || ($request['blocking'] ?? null) !== true
                || ($request['evidence']['requested_by_agent'] ?? null) !== AgentRole::PRINCIPAL_ARCHITECT->value
                || !$this->policy->isLegacyReadOnlyRefresh($request)) return false;

            if (!$this->repository->available()) {
                throw new RuntimeException('Read-only evidence requires existing repository access.');
            }
            $revision = $this->repository->currentBaseRevision();
            if (!preg_match('/^[0-9a-f]{40}$/i', $revision)) {
                throw new RuntimeException('Read-only evidence requires an authoritative revision.');
            }
            $oldRevision = $this->policy->requestedLegacyRevision($request)
                ?? trim((string) ($request['evidence']['repository_revision'] ?? ''));
            if ($oldRevision !== '' && $oldRevision !== $revision) {
                $comparison = $this->repository->compareRevisions($oldRevision, $revision);
                if (!in_array((string) ($comparison['status'] ?? ''), ['ahead','identical'], true)) {
                    throw new RuntimeException('Replacement repository revision failed ancestor revalidation.');
                }
            }
            $paths = $this->policy->legacyRefreshPaths([]);
            if ($paths === []) throw new RuntimeException('No bounded evidence paths were selected.');
            $existing = $this->repository->existingPathsAtRevision($paths, $revision);
            if (array_diff($paths, $existing) !== []) {
                throw new RuntimeException('Evidence files are absent from the validated repository revision.');
            }
            $directive = $this->coordinator->resumeAfterAutomaticEvidenceRefresh(
                $workflow, (string) $request['id'], $revision,
            );
            $this->decisions->autoResolveReadOnlyEvidence((string) $request['id']);
            foreach ($directive->transitions as $transition) {
                $this->workflows->saveTransition($workflow, $transition);
            }
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            $this->workflows->touchRuntime($workflowId);
            $this->journal->event(
                $featureId, $workflowId, 'MANAGER', 'manager.legacy_evidence_gate_auto_resumed',
                'COMPLETED', 'Read-only evidence reclassified as a system action.',
                $correlationId,
                ['request_id' => $request['id'], 'previous_revision' => $oldRevision, 'revision' => $revision, 'paths' => $paths],
            );
            return true;

        });
    }
}
