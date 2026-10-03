<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringCancelService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {}

    public function cancel(string $featureId, string $actorId, string $reason = 'Cancelled by human operator.'): array
    {
        $workflowId = $this->workflows->activeIdForFeature($featureId);
        if ($workflowId === null) throw new RuntimeException('Engineering feature has no active workflow.');

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $actorId, $reason): array {
            $workflow = $this->workflows->get($workflowId);
            $directive = $this->coordinator->cancel($workflow, $actorId, $reason);
            $this->persistTransitions($workflow, $directive->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);

            return [
                'feature_id' => $featureId,
                'workflow_id' => $workflowId,
                'state' => $workflow->currentState()->value,
                'reason' => $reason,
            ];
        });
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
