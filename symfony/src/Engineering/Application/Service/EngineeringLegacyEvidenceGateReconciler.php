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
            // REVALIDATE_AND_RESUME
        });
    }
}
