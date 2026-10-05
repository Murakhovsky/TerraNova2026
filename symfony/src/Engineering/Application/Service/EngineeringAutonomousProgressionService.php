<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringAutonomousProgressionService
{
    public function __construct(
        private EngineeringProductRequirementsStageExecutor $product,
        private EngineeringArchitectStageExecutor $architect,
        private EngineeringDeveloperStageExecutor $developer,
        private EngineeringReviewerStageExecutor $reviewer,
        private EngineeringQaStageExecutor $qa,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
        private int $maxStepsPerProgression = 16,
        private int $maxLogicalAgentRunsPerFeature = 16,
    ) {}

    public function continue(
        string $featureId,
        string $workflowId,
        WorkflowDirective $directive,
        string $organizationId,
        string $correlationId,
    ): WorkflowDirective {
        for ($step = 0; $step < $this->maxStepsPerProgression; ++$step) {
            $role = $directive->agent;
            if ($role === null) return $directive;

            if (!$this->experienceAutonomyAllows($featureId, $workflowId, $role)) {
                $level = $this->experienceAutonomyLevel($featureId) ?? 'L0';
                return new WorkflowDirective(
                    WorkflowDirectiveType::STOP,
                    null,
                    'Experience autonomy ceiling '.$level.' reached before '.$role->value.'.',
                );
            }

            if ($this->runCount($featureId) >= $this->authorizedRunBudget($featureId)) {
                return $this->escalateAutonomyBudget(
                    $featureId,
                    $workflowId,
                    'Persistent autonomous agent-run budget exhausted before scheduling '.$role->value.'.',
                );
            }

            $directive = match ($role) {
                AgentRole::PRODUCT_REQUIREMENTS => $this->product->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::PRODUCT_REQUIREMENTS),
                ),
                AgentRole::PRINCIPAL_ARCHITECT => $this->architect->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::PRINCIPAL_ARCHITECT),
                ),
                AgentRole::DEVELOPER => $this->developer->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::DEVELOPER),
                ),
                AgentRole::REVIEWER => $this->reviewer->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::REVIEWER),
                ),
                AgentRole::QA_PLANNER,
                AgentRole::QA_EXECUTOR,
                AgentRole::QA => $this->qa->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, $role),
                ),
                AgentRole::ENGINEERING_MANAGER,
                AgentRole::INTEGRATION_RELEASE => $directive,
            };

            if ($directive->agent === $role) return $directive;
        }

        return $this->escalateAutonomyBudget(
            $featureId,
            $workflowId,
            'Single autonomous progression exceeded its safety step budget.',
        );
    }

    private function experienceAutonomyAllows(string $featureId, string $workflowId, AgentRole $role): bool
    {
        $level = $this->experienceAutonomyLevel($featureId);
        if ($level === null) {
            return true;
        }

        $rank = match ($level) {
            'L0' => 0,
            'L1' => 1,
            'L2' => 2,
            'L3' => 3,
            default => 2,
        };

        $workflow = $this->workflows->get($workflowId);
        $required = match ($role) {
            AgentRole::ENGINEERING_MANAGER => 0,
            AgentRole::PRODUCT_REQUIREMENTS,
            AgentRole::QA_PLANNER,
            AgentRole::PRINCIPAL_ARCHITECT => 1,
            AgentRole::DEVELOPER => 2,
            AgentRole::REVIEWER,
            AgentRole::QA_EXECUTOR,
            AgentRole::INTEGRATION_RELEASE => 3,
            AgentRole::QA => $workflow->currentState()->value === 'QA_PLANNING' ? 1 : 3,
        };

        return $rank >= $required;
    }

    private function experienceAutonomyLevel(string $featureId): ?string
    {
        $request = $this->features->request($featureId);
        if ($request->sourceType !== 'experience_page_delivery') {
            return null;
        }

        $level = strtoupper(trim((string) ($request->metadata['experience_autonomy_level'] ?? 'L2')));
        return match ($level) {
            'L0', 'L1', 'L2', 'L3' => $level,
            default => 'L2',
        };
    }

    private function nextAttempt(string $featureId, AgentRole $role): int
    {
        $count = 0;
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (($run['role'] ?? null) === $role->value) ++$count;
        }
        return $count + 1;
    }

    private function runCount(string $featureId): int
    {
        return count($this->agentRuns->forFeature($featureId));
    }

    private function authorizedRunBudget(string $featureId): int
    {
        $extensions = 0;
        foreach ($this->humanDecisions->historyForFeature($featureId) as $decision) {
            if (($decision['type'] ?? null) !== 'AUTONOMY_BUDGET') continue;
            if (($decision['status'] ?? null) !== 'ANSWERED') continue;
            if (strtoupper(trim((string) ($decision['answer']['selected_option'] ?? ''))) === 'CONTINUE') ++$extensions;
        }

        return $this->maxLogicalAgentRunsPerFeature * (1 + $extensions);
    }

    private function escalateAutonomyBudget(string $featureId, string $workflowId, string $reason): WorkflowDirective
    {
        return $this->lock->synchronized(
            $featureId,
            function () use ($featureId, $workflowId, $reason): WorkflowDirective {
                $workflow = $this->workflows->get($workflowId);
                $next = $this->coordinator->requireHumanDecision($workflow, $reason);

                foreach ($next->transitions as $transition) {
                    $this->workflows->saveTransition($workflow, $transition);
                }
                $this->features->updateStatus($featureId, $workflow->currentState()->value);

                if ($this->humanDecisions->openForFeature($featureId) === []) {
                    $this->humanDecisions->create(
                        featureId: $featureId,
                        workflowId: $workflowId,
                        type: 'AUTONOMY_BUDGET',
                        question: 'The autonomous Engineering cycle exhausted its safety budget. Continue autonomously?',
                        reason: $reason,
                        options: [
                            ['id' => 'CONTINUE', 'description' => 'Resume from the persisted workflow state with human authorization.'],
                            ['id' => 'CANCEL', 'description' => 'Stop this Engineering workflow.'],
                        ],
                        evidence: [
                            'logical_agent_runs' => $this->runCount($featureId),
                            'base_logical_agent_run_budget' => $this->maxLogicalAgentRunsPerFeature,
                            'authorized_logical_agent_run_budget' => $this->authorizedRunBudget($featureId),
                            'max_steps_per_progression' => $this->maxStepsPerProgression,
                        ],
                        blocking: true,
                        recommendedOption: 'CANCEL',
                    );
                }

                return $next;
            },
        );
    }
}
