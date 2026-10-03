<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

final readonly class EngineeringOrchestrator
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringManagerStageExecutor $managerStage,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {
    }

    public function create(EngineeringRequest $request, ?string $createdBy = null): string
    {
        $featureId = EngineeringId::generate();
        $this->features->create($featureId, $request, $createdBy);
        return $featureId;
    }

    public function start(string $featureId, string $organizationId, string $correlationId): EngineeringStartResult
    {
        /** @var WorkflowExecution $workflow */
        $workflow = $this->lock->synchronized($featureId, function () use ($featureId, $correlationId): WorkflowExecution {
            $active = $this->workflows->activeIdForFeature($featureId);
            if ($active !== null) {
                throw new WorkflowAlreadyRunningException('Engineering feature already has active workflow '.$active);
            }

            $workflow = new WorkflowExecution(
                EngineeringId::generate(),
                $featureId,
                EngineeringWorkflowState::NEW,
                $correlationId,
            );
            $this->workflows->create($workflow);
            $start = $this->coordinator->startAnalysis($workflow);
            $this->persistTransitions($workflow, $start->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $workflow;
        });

        $next = $this->managerStage->execute(
            featureId: $featureId,
            workflowId: $workflow->id(),
            request: $this->features->request($featureId),
            organizationId: $organizationId,
            correlationId: $correlationId,
            logicalAttempt: 1,
        );

        $finalWorkflow = $this->workflows->get($workflow->id());
        return new EngineeringStartResult(
            $featureId,
            $workflow->id(),
            $finalWorkflow->currentState()->value,
            $next,
        );
    }

    /** @param list<\App\Engineering\Domain\Workflow\WorkflowTransition> $transitions */
    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
