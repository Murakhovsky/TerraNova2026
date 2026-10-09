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
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::PRODUCT_REQUIREMENTS, 'Engineering request requires Product / Requirements analysis.', [$transition]);
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
                AgentRole::PRODUCT_REQUIREMENTS,
                'Human decision supplied; rerun Product / Requirements analysis with the decision in context.',
                [$transition],
            ),
            EngineeringWorkflowState::QA_PLANNING => new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::QA_PLANNER,
                'Human decision supplied; resume QA Test Plan.',
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
                AgentRole::QA_EXECUTOR,
                'Human decision supplied; resume QA execution.',
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

    /**
     * Restore a legacy evidence-only gate using a SYSTEM transition.
     * This is not a human approval and may only resume the Architect stage.
     */
    public function resumeAfterAutomaticEvidenceRefresh(
        WorkflowExecution $workflow,
        string $requestId,
        string $revision,
    ): WorkflowDirective {
        if ($workflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED
            || $workflow->resumeState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
            throw new LogicException('Only an Architect evidence-only gate may be automatically resumed.');
        }

        $transition = $this->engine->transition(
            $workflow,
            EngineeringWorkflowState::ARCHITECTURE_PENDING,
            new WorkflowTransitionContext(
                trigger: 'REPOSITORY_EVIDENCE_AUTO_AUTHORIZED',
                reason: 'Manager policy classified read-only repository evidence as a technical action.',
                initiatedByType: 'SYSTEM',
                initiatedById: 'engineering-manager-policy',
                metadata: ['evidence_request_id' => $requestId, 'repository_revision' => $revision],
            ),
        );
        return new WorkflowDirective(
            WorkflowDirectiveType::RUN_AGENT,
            AgentRole::PRINCIPAL_ARCHITECT,
            'Repository evidence refresh is authorized; rerun Architect without human approval.',
            [$transition],
        );
    }

    public function specialistRework(WorkflowExecution $workflow, string $reason): WorkflowDirective
    {
        if ($workflow->currentState() === EngineeringWorkflowState::DEVELOPMENT_RUNNING) {
            $transition = $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, 'SPECIALIST_ARCHITECTURE_REWORK_REQUIRED');
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::PRINCIPAL_ARCHITECT,
                $reason,
                [$transition],
            );
        }

        if ($workflow->currentState() === EngineeringWorkflowState::REVIEW_PENDING) {
            $transitions = [
                $this->transition($workflow, EngineeringWorkflowState::CHANGES_REQUESTED, 'SPECIALIST_IMPLEMENTATION_REWORK_REQUIRED'),
                $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'SPECIALIST_DEVELOPER_FIX_STARTED'),
            ];
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::DEVELOPER,
                $reason,
                $transitions,
            );
        }

        return $this->human($workflow, $reason);
    }

    public function requireHumanDecision(WorkflowExecution $workflow, string $reason): WorkflowDirective
    {
        return $this->human($workflow, $reason);
    }

    public function cancel(
        WorkflowExecution $workflow,
        string $actorId,
        string $reason = 'Cancelled by human operator.',
    ): WorkflowDirective {
        if ($workflow->currentState()->isTerminal()) {
            throw new LogicException('Terminal engineering workflow cannot be cancelled.');
        }
        if (trim($actorId) === '') {
            throw new LogicException('Engineering workflow cancellation requires an actor.');
        }

        $transition = $this->engine->transition(
            $workflow,
            EngineeringWorkflowState::CANCELLED,
            new WorkflowTransitionContext(
                trigger: 'HUMAN_CANCELLED',
                reason: $reason,
                initiatedByType: 'HUMAN',
                initiatedById: $actorId,
            ),
        );

        return new WorkflowDirective(
            WorkflowDirectiveType::STOP,
            null,
            'Engineering workflow cancelled.',
            [$transition],
        );
    }

    public function completeAfterHumanApproval(
        WorkflowExecution $workflow,
        string $actorId,
        string $mergeRevision,
    ): WorkflowDirective {
        if ($workflow->currentState() !== EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
            throw new LogicException('Engineering workflow is not ready for human approval.');
        }
        if (trim($actorId) === '' || trim($mergeRevision) === '') {
            throw new LogicException('Human approval requires actor and merge revision evidence.');
        }

        $transition = $this->engine->transition(
            $workflow,
            EngineeringWorkflowState::DONE,
            new WorkflowTransitionContext(
                trigger: 'HUMAN_MERGE_CONFIRMED',
                reason: 'Human merge confirmed in GitHub.',
                initiatedByType: 'HUMAN',
                initiatedById: $actorId,
                metadata: ['merge_revision' => $mergeRevision],
            ),
        );

        return new WorkflowDirective(
            WorkflowDirectiveType::STOP,
            null,
            'Engineering workflow completed after verified human merge.',
            [$transition],
        );
    }

    public function acceptAgentResult(
        WorkflowExecution $workflow,
        AgentRole $role,
        array $output,
        WorkflowCounters $counters = new WorkflowCounters(),
        ?ReadyForHumanApprovalEvidence $readyEvidence = null,
    ): WorkflowDirective {
        return match ($role) {
            AgentRole::PRODUCT_REQUIREMENTS => $this->afterProduct($workflow, $output),
            AgentRole::QA_PLANNER => $this->afterQaPlanner($workflow, $output),
            AgentRole::PRINCIPAL_ARCHITECT => $this->afterArchitect($workflow, $output),
            AgentRole::DEVELOPER => $this->afterDeveloper($workflow, $output),
            AgentRole::REVIEWER => $this->afterReviewer($workflow, $output, $counters),
            AgentRole::QA_EXECUTOR => $this->afterQaExecutor($workflow, $output, $counters, $readyEvidence),
            default => throw new LogicException('Agent role is not part of the Feature execution path: '.$role->value),
        };
    }

    private function afterProduct(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'HUMAN_DECISION_REQUIRED') return $this->human($workflow, 'Product Agent requires a blocking product decision.');
        if (in_array($status, ['BLOCKED','FAILED'], true)) return $this->block($workflow, 'Product requirements analysis could not complete.');
        if ($status !== 'SPECIFICATION_READY') throw new LogicException('Unexpected Product / Requirements status: '.$status);

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::SPECIFICATION_READY, 'PRODUCT_SPECIFICATION_READY'),
            $this->transition($workflow, EngineeringWorkflowState::QA_PLANNING, 'SCHEDULE_QA_TEST_PLAN'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::QA_PLANNER, 'Acceptance criteria require an independent QA Test Plan before architecture and implementation.', $transitions);
    }

    private function afterArchitect(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'NEEDS_HUMAN_DECISION') return $this->human($workflow, 'Architect requires a human architecture/product decision.');
        if ($status === 'REJECTED') return $this->block($workflow, 'Architecture gate rejected the feature for development.');
        if (!in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) throw new LogicException('Unexpected Architect status: '.$status);

        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_APPROVED, 'ARCHITECTURE_APPROVED'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_PENDING, 'SCHEDULE_DEVELOPER'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'DEVELOPER_STARTED'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'Approved architecture is ready for implementation.', $transitions);
    }

    public function revalidateArchitecture(WorkflowExecution $workflow, string $reason): WorkflowDirective
    {
        if ($workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) {
            throw new LogicException('Architecture revalidation can be requested only before Developer execution.');
        }

        $transition = $this->transition(
            $workflow,
            EngineeringWorkflowState::ARCHITECTURE_PENDING,
            'ARCHITECTURE_REVALIDATION_REQUIRED',
        );

        return new WorkflowDirective(
            WorkflowDirectiveType::RUN_AGENT,
            AgentRole::PRINCIPAL_ARCHITECT,
            $reason,
            [$transition],
        );
    }

    private function afterDeveloper(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');

        if ($status === 'ARCHITECTURE_REVIEW_REQUIRED') {
            return $this->revalidateArchitecture(
                $workflow,
                'Developer preflight found an architecture/implementation-plan conflict that requires Principal Architect review.',
            );
        }
        if ($status === 'SPECIFICATION_REVIEW_REQUIRED') {
            $transition = $this->transition($workflow, EngineeringWorkflowState::ANALYSIS, 'DEVELOPER_SPECIFICATION_REVIEW_REQUIRED');
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::PRODUCT_REQUIREMENTS,
                'Developer found a specification/acceptance-criteria conflict; Product must revalidate requirements.',
                [$transition],
            );
        }
        $developerSpecialists = [
            'SECURITY_REVIEW_REQUIRED' => AgentRole::SECURITY_SPECIALIST,
            'MIGRATION_REVIEW_REQUIRED' => AgentRole::DATABASE_MIGRATION_SPECIALIST,
            'PERFORMANCE_REVIEW_REQUIRED' => AgentRole::PERFORMANCE_SPECIALIST,
            'DEVOPS_REVIEW_REQUIRED' => AgentRole::DEVOPS_SPECIALIST,
            'API_REVIEW_REQUIRED' => AgentRole::API_SPECIALIST,
        ];
        if (isset($developerSpecialists[$status])) {
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                $developerSpecialists[$status],
                'Developer requires independent specialist review before implementation can continue.',
            );
        }
        if ($status === 'BLOCKED') return $this->block($workflow, 'Developer reported a non-retryable blocker.');
        if ($status === 'FAILED') return $this->block($workflow, 'Developer failed without a retryable runtime classification.');
        if (!in_array($status, ['COMPLETED','COMPLETED_WITH_LIMITATIONS'], true)) {
            throw new LogicException('Unexpected Developer status: '.$status);
        }

        $transition = $this->transition($workflow, EngineeringWorkflowState::REVIEW_PENDING, 'DEVELOPMENT_COMPLETED');
        return new WorkflowDirective(
            WorkflowDirectiveType::RUN_AGENT,
            AgentRole::REVIEWER,
            $status === 'COMPLETED_WITH_LIMITATIONS'
                ? 'Implementation completed with explicit limitations and requires review.'
                : 'Completed implementation requires review.',
            [$transition],
        );
    }

    private function afterReviewer(WorkflowExecution $workflow, array $output, WorkflowCounters $counters): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($status === 'APPROVED') {
            $transition = $this->transition($workflow, EngineeringWorkflowState::QA_PENDING, 'REVIEW_APPROVED');
            return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::QA_EXECUTOR, 'Independent review approved the implementation; final QA execution is required.', [$transition]);
        }
        if ($status === 'ARCHITECTURE_REVIEW_REQUIRED') {
            $transition = $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, 'REVIEW_ARCHITECTURE_REVIEW_REQUIRED');
            return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::PRINCIPAL_ARCHITECT, 'Reviewer found implementation evidence that requires Principal Architect revalidation.', [$transition]);
        }
        $reviewSpecialists = [
            'SECURITY_REVIEW_REQUIRED' => AgentRole::SECURITY_SPECIALIST,
            'MIGRATION_REVIEW_REQUIRED' => AgentRole::DATABASE_MIGRATION_SPECIALIST,
            'PERFORMANCE_REVIEW_REQUIRED' => AgentRole::PERFORMANCE_SPECIALIST,
            'DEVOPS_REVIEW_REQUIRED' => AgentRole::DEVOPS_SPECIALIST,
            'API_REVIEW_REQUIRED' => AgentRole::API_SPECIALIST,
        ];
        if (isset($reviewSpecialists[$status])) {
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                $reviewSpecialists[$status],
                'Reviewer requires independent specialist revalidation.',
            );
        }
        if ($status === 'HUMAN_REVIEW_REQUIRED') return $this->human($workflow, 'Reviewer identified a decision that requires human review.');
        if ($status !== 'REQUEST_CHANGES') throw new LogicException('Unexpected Reviewer status: '.$status);
        if (!$this->retries->mayRunReview($counters->reviewCycles) || !$this->retries->mayRunDevelopmentFix($counters->developmentFixLoops)) {
            $escalated = $this->transition($workflow, EngineeringWorkflowState::ESCALATED, 'REVIEW_LOOP_LIMIT');
            return $this->human($workflow, 'Review/development loop limit exceeded.', [$escalated]);
        }
        $transitions = [
            $this->transition($workflow, EngineeringWorkflowState::CHANGES_REQUESTED, 'REVIEW_CHANGES_REQUESTED'),
            $this->transition($workflow, EngineeringWorkflowState::DEVELOPMENT_RUNNING, 'DEVELOPER_FIX_STARTED'),
        ];
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'Reviewer requested bounded implementation changes.', $transitions);
    }

    private function afterQaPlanner(WorkflowExecution $workflow, array $output): WorkflowDirective
    {
        $status = (string) ($output['status'] ?? '');
        if ($workflow->currentState() !== EngineeringWorkflowState::QA_PLANNING) {
            throw new LogicException('QA Planner result received outside QA_PLANNING.');
        }
        if ($status === 'BLOCKED') return $this->block($workflow, 'QA Planner could not produce a reliable Test Plan.');
        if ($status === 'HUMAN_TEST_REQUIRED') return $this->human($workflow, 'QA planning requires a human testing decision.');
        if ($status !== 'PLAN_READY') throw new LogicException('Unexpected QA Planner status: '.$status);

        $transition = $this->transition($workflow, EngineeringWorkflowState::ARCHITECTURE_PENDING, 'QA_TEST_PLAN_READY');
        return new WorkflowDirective(
            WorkflowDirectiveType::RUN_AGENT,
            AgentRole::PRINCIPAL_ARCHITECT,
            'QA Test Plan is ready; architecture must account for testability and required verification.',
            [$transition],
        );
    }

    private function afterQaExecutor(
        WorkflowExecution $workflow,
        array $output,
        WorkflowCounters $counters,
        ?ReadyForHumanApprovalEvidence $readyEvidence,
    ): WorkflowDirective {
        $status = (string) ($output['status'] ?? '');

        if ($status === 'TESTS_UPDATED') {
            $transition = $this->transition($workflow, EngineeringWorkflowState::REVIEW_PENDING, 'QA_TESTS_UPDATED_REVIEW_REQUIRED');
            return new WorkflowDirective(
                WorkflowDirectiveType::RUN_AGENT,
                AgentRole::REVIEWER,
                'QA added bounded automated tests; the new revision requires independent review before QA execution continues.',
                [$transition],
            );
        }
        if ($status === 'HUMAN_TEST_REQUIRED') return $this->human($workflow, 'QA requires explicit human testing evidence before completion.');
        if ($status === 'BLOCKED') return $this->block($workflow, 'QA reported a non-retryable verification blocker.');
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
        return new WorkflowDirective(WorkflowDirectiveType::RUN_AGENT, AgentRole::DEVELOPER, 'QA behavior defects require Developer fixes, then a new Reviewer and QA cycle.', $transitions);
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
