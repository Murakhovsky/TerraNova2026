<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringRetryPolicy;
use App\Engineering\Domain\Workflow\EngineeringWorkflowEngine;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalEvidence;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalGuard;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransition;
use App\Engineering\Domain\Workflow\WorkflowTransitionContext;
use LogicException;

final readonly class EngineeringWorkflowCoordinator
{
    public function __construct(
        private EngineeringWorkflowEngine $engine = new EngineeringWorkflowEngine(),
        private EngineeringRetryPolicy $retries = new EngineeringRetryPolicy(),
        private ReadyForHumanApprovalGuard $ready = new ReadyForHumanApprovalGuard(),
    ) {}

    public function startAnalysis(WorkflowExecution $workflow): WorkflowDirective
    {
        $transition = $this->transition($workflow, EngineeringWorkflowState::ANALYSIS, 'START_ANALYSIS');
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::ENGINEERING_MANAGER, 'Engineering request requires formal analysis.', [$transition]);
    }

    public function resumeAfterHumanDecision(
        WorkflowExecution $workflow,
        string $humanDecisionId,
    ): WorkflowDirective {
        if ($workflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
            throw new LogicException('Engineering workflow is not waiting for a human decision.');
        }

        $resume = $workflow->resumeState();
        if ($resume === null) {
            throw new LogicException('Engineering workflow has no persisted resume state.');
        }

        $transition = $this->engine->transition(
            $workflow,
            $resume,
            new WorkflowTransitionContext(
                trigger: 'HUMAN_DECISION_ANSWERED',
                reason: 'Human decision answered; resume persisted workflow state.',
                initiatedByType: 'HUMAN',
                initiatedById: 'decision:'.$humanDecisionId,
                humanDecisionId: $humanDecisionId,
            ),
        );

        return match ($resume) {
            EngineeringWorkflowState::ANALYSIS => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::ENGINEERING_MANAGER,
                'Human decision supplied; rerun Manager analysis with the decision in context.',
                [$transition],
            ),
            EngineeringWorkflowState::ARCHITECTURE_PENDING => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::PRINCIPAL_ARCHITECT,
                'Human decision supplied; resume architecture.',
                [$transition],
            ),
            EngineeringWorkflowState::DEVELOPMENT_RUNNING,
            EngineeringWorkflowState::CHANGES_REQUESTED,
            EngineeringWorkflowState::QA_FAILED => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::DEVELOPER,
                'Human decision supplied; resume development.',
                [$transition],
            ),
            EngineeringWorkflowState::REVIEW_PENDING => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::REVIEWER,
                'Human decision supplied; resume review.',
                [$transition],
            ),
            EngineeringWorkflowState::QA_PENDING => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::QA,
                'Human decision supplied; resume QA.',
                [$transition],
            ),
            default => new WorkflowDirective(
                WorkflowDirectiveType::STOP,
                null,
                'Human decision recorded. Workflow resumed to '.$resume->value.' and requires explicit orchestration.',
                [$transition],
            ),
        };
    }

    public function acceptAgentResult(
        WorkflowExecution $workflow,
        AgentRole $role,
        array $output,
        WorkflowCounters $counters = new WorkflowCounters(),
        ?ReadyForHumanApprovalEvidence $readyEvidence = null,
    ): WorkflowDirective {
        return match ($role) {
            AgentRole::ENGINEERING_MANAGER => $this->afterManager($workflow, $output),
            AgentRole::PRINCIPAL_ARCHITECT => $this->afterArchitect($workflow, $output),
            AgentRole::DEVELOPER => $this->afterDeveloper($workflow, $output),
            AgentRole::REVIEWER => $this->afterReviewer($workflow, $output, $counters),
            AgentRole::QA => $this->afterQa($workflow, $output, $counters, $readyEvidence),
        };
    }

    private function afterManager(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'HUMAN_DECISION_REQUIRED') return $this->human($workflow, 'Manager found a blocking product decision.');
        if (in_array($status, ['BLOCKED','FAILED'], true)) return $this->block($workflow, 'Manager analysis could not complete.');
        if ($status !== 'SPECIFICATION_READY') throw new LogicException('Unexpected Engineering Manager status: '.$status);

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::SPECIFICATION_READY, 'MANAGER_SPECIFICATION_READY'),
            $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, 'SCHEDULE_ARCHITECT'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::PRINCIPAL_ARCHITECT, 'Architecture is mandatory for V0.1.', $transitions);
    }

    private function afterArchitect(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'NEEDS_PRODUCT_DECISION') return $this->human($workflow, 'Architect requires a product decision.');
        if (in_array($status, ['BLOCKED','REJECTED'], true)) return $this->block($workflow, 'Architecture stage blocked the feature.');
        if (!in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) throw new LogicException('Unexpected Architect status: '.$status);

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_APPROVED, 'ARCHITECTURE_APPROVED'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_PENDING, 'SCHEDULE_DEVELOPER'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'DEVELOPER_STARTED'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'Approved architecture is ready for implementation.', $transitions);
    }

    private function afterDeveloper(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'BLOCKED') return $this->block($workflow, 'Developer reported a non-retryable blocker.');
        if ($status === 'FAILED') return $this->block($workflow, 'Developer failed without a retryable runtime classification.');
        if ($status !== 'COMPLETED') throw new LogicException('Unexpected Developer status: '.$status);

        $transition = $this->transition($workflow, EngineeringWorkflowState::REVIEW_PENDING, 'DEVELOPMENT_COMPLETED');
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::REVIEWER, 'Completed implementation requires review.', [$transition]);
    }

    private function afterReviewer(WorkflowExecution $workflow, array $output, WorkflowCounters $counters): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'BLOCKED') return $this->block($workflow, 'Reviewer reported a blocker.');
        if ($status === 'APPROVED') {
            $transition = $this->transition($workflow, EngineeringWorkflowState::QA_PENDING, 'REVIEW_APPROVED');
            return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::QA, 'Reviewed implementation requires final QA.', [$transition]);
        }
        if ($status !== 'CHANGES_REQUESTED') throw new LogicException('Unexpected Reviewer status: '.$status);

        if (!$this->retries->mayRunReview($counters->reviewCycles) || !$this->retries->mayRunDevelopmentFix($counters->developmentFixLoops)) {
            $escalated = $this->transition($workflow, EngineeringWorkflowState::ESCALATED, 'REVIEW_LOOP_LIMIT');
            return $this->human($workflow, 'Review/development loop limit exceeded.', [$escalated]);
        }

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::CHANGES_REQUESTED, 'REVIEW_CHANGES_REQUESTED'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'DEVELOPER_FIX_STARTED'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'Reviewer requested implementation changes.', $transitions);
    }

    private function afterQa(
        WorkflowExecution $workflow,
        array $output,
        WorkflowCounters $counters,
        ?ReadyForHumanApprovalEvidence $readyEvidence,
    ): WorkflowDirective {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'BLOCKED') return $this->block($workflow, 'QA reported a blocker.');
        if ($status === 'PASS') {
            if ($readyEvidence === null) throw new LogicException('QA PASS requires READY gate evidence.');
            $this->ready->assert($readyEvidence);
            $transition = $this->transition($workflow, EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL, 'QA_AND_READY_GATE_PASSED');
            return new WorkflowDirective(WorkflowDirectiveType::READY_FOR_HUMAN_APPROVAL, null, 'All deterministic completion gates passed.', [$transition]);
        }
        if ($status !== 'FAIL') throw new LogicException('Unexpected QA status: '.$status);

        if (!$this->retries->mayRunQa($counters->qaCycles) || !$this->retries->mayRunDevelopmentFix($counters->developmentFixLoops)) {
            $escalated = $this->transition($workflow, EngineeringWorkflowState::ESCALATED, 'QA_LOOP_LIMIT');
            return $this->human($workflow, 'QA/development loop limit exceeded.', [$escalated]);
        }

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::QA_FAILED, 'QA_FAILED'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'DEVELOPER_QA_FIX_STARTED'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'QA defects require development fixes and a new review.', $transitions);
    }

    /** @param list<WorkflowTransition> $transitions */
    private function human(WorkflowExecution $workflow, string $reason, array $transitions = []): WorkflowDirective
    {
        if ($workflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
            $transitions[] = $this->transition($workflow, EngineeringWorkflowState::HUMAN_DECISION_REQUIRED, 'HUMAN_DECISION_REQUIRED');
        }
        return new WorkflowDirective(WorkflowDirectiveType::REQUEST_HUMAN_DECISION, null, $reason, $transitions);
    }

    private function block(WorkflowExecution $workflow, string $reason): WorkflowDirective
    {
        $transition = $this->transition($workflow, EngineeringWorkflowState::BLOCKED, 'BLOCKED');
        return new WorkflowDirective(WorkflowDirectiveType::BLOCK, null, $reason, [$transition]);
    }

    private function transition(WorkflowExecution $workflow, EngineeringWorkflowState $target, string $trigger): WorkflowTransition
    {
        return $this->engine->transition(
            $workflow,
            $target,
            new WorkflowTransitionContext($trigger, $trigger, 'SYSTEM', 'engineering-workflow-coordinator'),
        );
    }
}
