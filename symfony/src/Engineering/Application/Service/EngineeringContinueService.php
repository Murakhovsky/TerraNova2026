<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use RuntimeException;

final readonly class EngineeringContinueService
{
    public function __construct(
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringManagerStageExecutor $managerStage,
        private EngineeringAutonomousProgressionService $progression,
        private int $staleRunSeconds = 1800,
    ) {}

    public function continueFeature(
        string $featureId,
        string $organizationId,
        string $correlationId,
    ): EngineeringStartResult {
        $feature = $this->features->view($featureId);
        if (($feature['organization_id'] ?? null) !== $organizationId) {
            throw new RuntimeException('Engineering feature does not belong to the current organization.');
        }

        $workflowId = $this->workflows->activeIdForFeature($featureId);
        if ($workflowId === null) throw new RuntimeException('Engineering feature has no active workflow.');
        $this->workflows->touchRuntime($workflowId);
        $workflow = $this->workflows->get($workflowId);
        $this->features->updateStatus($featureId, $workflow->currentState()->value);
        $activeRole = $this->roleForState($featureId, $workflow->currentState());
        if ($activeRole !== null) {
            $recoveredStaleRuns = $this->agentRuns->failStaleRunning($featureId, $activeRole, $this->staleRunSeconds);
            if ($recoveredStaleRuns > 0) {
                $this->features->appendPreviousContext($featureId, [
                    'engineering_recovery' => [
                        'workflow_id' => $workflowId,
                        'state' => $workflow->currentState()->value,
                        'correlation_id' => $correlationId,
                        'attempted_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                        'recovered_stale_runs' => $recoveredStaleRuns,
                    ],
                ]);
            }
            if ($this->hasRunningRole($featureId, $activeRole)) {
                $next = new WorkflowDirective(
                    WorkflowDirectiveType::STOP,
                    null,
                    $activeRole->value.' AgentRun is still RUNNING; recovery must not create a duplicate logical attempt.',
                );
                return new EngineeringStartResult(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    state: $workflow->currentState()->value,
                    next: $next,
                );
            }
        }

        if ($workflow->currentState() === EngineeringWorkflowState::ANALYSIS) {
            if ($activeRole === AgentRole::PRODUCT_REQUIREMENTS) {
                $next = new WorkflowDirective(
                    WorkflowDirectiveType::RUN_AGENT,
                    AgentRole::PRODUCT_REQUIREMENTS,
                    'Resume Product / Requirements stage after completed Manager analysis.',
                );
            } else {
                $next = $this->managerStage->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    request: $this->features->request($featureId),
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::ENGINEERING_MANAGER),
                );
            }
            $next = $this->progression->continue($featureId, $workflowId, $next, $organizationId, $correlationId);
        } else {
            $next = $this->directiveFor($featureId, $workflow->currentState());
            $next = $this->progression->continue($featureId, $workflowId, $next, $organizationId, $correlationId);
        }

        $this->workflows->touchRuntime($workflowId);
        $current = $this->workflows->get($workflowId);
        return new EngineeringStartResult(
            featureId: $featureId,
            workflowId: $workflowId,
            state: $current->currentState()->value,
            next: $next,
        );
    }

    private function roleForState(string $featureId, EngineeringWorkflowState $state): ?AgentRole
    {
        if ($state === EngineeringWorkflowState::ANALYSIS) {
            $managerCompleted = false;
            foreach ($this->agentRuns->forFeature($featureId) as $run) {
                if (($run['role'] ?? null) === AgentRole::PRODUCT_REQUIREMENTS->value && ($run['status'] ?? null) === 'RUNNING') {
                    return AgentRole::PRODUCT_REQUIREMENTS;
                }
                if (($run['role'] ?? null) === AgentRole::ENGINEERING_MANAGER->value && ($run['status'] ?? null) === 'COMPLETED') {
                    $managerCompleted = true;
                }
            }
            return $managerCompleted ? AgentRole::PRODUCT_REQUIREMENTS : AgentRole::ENGINEERING_MANAGER;
        }

        $v2 = $this->isV2Feature($featureId);
        return match ($state) {
            EngineeringWorkflowState::QA_PLANNING => $v2 ? AgentRole::QA_PLANNER : AgentRole::QA,
            EngineeringWorkflowState::QA_PENDING => $v2 ? AgentRole::QA_EXECUTOR : AgentRole::QA,
            EngineeringWorkflowState::ARCHITECTURE_PENDING => AgentRole::PRINCIPAL_ARCHITECT,
            EngineeringWorkflowState::DEVELOPMENT_RUNNING => AgentRole::DEVELOPER,
            EngineeringWorkflowState::REVIEW_PENDING => AgentRole::REVIEWER,
            default => null,
        };
    }

    private function directiveFor(string $featureId, EngineeringWorkflowState $state): WorkflowDirective
    {
        $v2 = $this->isV2Feature($featureId);
        return match ($state) {
            EngineeringWorkflowState::QA_PLANNING => new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, $v2 ? AgentRole::QA_PLANNER : AgentRole::QA, 'Resume QA Test Plan stage.'),
            EngineeringWorkflowState::ARCHITECTURE_PENDING => new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::PRINCIPAL_ARCHITECT, 'Resume Architect stage.'),
            EngineeringWorkflowState::DEVELOPMENT_RUNNING => new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'Resume Developer stage.'),
            EngineeringWorkflowState::REVIEW_PENDING => new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::REVIEWER, 'Resume Reviewer stage.'),
            EngineeringWorkflowState::QA_PENDING => new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, $v2 ? AgentRole::QA_EXECUTOR : AgentRole::QA, 'Resume QA stage and re-check CI.'),
            EngineeringWorkflowState::HUMAN_DECISION_REQUIRED => new WorkflowDirective(WorkflowDirectiveType::STOP, null, 'Workflow is waiting for a human decision.'),
            EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL => new WorkflowDirective(WorkflowDirectiveType::READY_FOR_HUMAN_APPROVAL, null, 'Workflow is ready for human approval/merge.'),
            EngineeringWorkflowState::BLOCKED,
            EngineeringWorkflowState::ESCALATED => new WorkflowDirective(WorkflowDirectiveType::STOP, null, 'Workflow requires explicit human intervention from state '.$state->value.'.'),
            default => new WorkflowDirective(WorkflowDirectiveType::STOP, null, 'No autonomous continuation is defined from state '.$state->value.'.'),
        };
    }

    private function isV2Feature(string $featureId): bool
    {
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (in_array(($run['role'] ?? null), [
                AgentRole::PRODUCT_REQUIREMENTS->value,
                AgentRole::QA_PLANNER->value,
                AgentRole::QA_EXECUTOR->value,
            ], true)) {
                return true;
            }
        }
        return false;
    }

    private function hasRunningRole(string $featureId, AgentRole $role): bool
    {
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (($run['role'] ?? null) === $role->value && ($run['status'] ?? null) === 'RUNNING') return true;
        }
        return false;
    }

    private function nextAttempt(string $featureId, AgentRole $role): int
    {
        $count = 0;
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (($run['role'] ?? null) === $role->value) ++$count;
        }
        return $count + 1;
    }
}
