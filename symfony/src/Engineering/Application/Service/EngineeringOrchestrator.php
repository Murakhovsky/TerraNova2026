<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Manager\EngineeringManagerAnalysisService;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

final readonly class EngineeringOrchestrator
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringManagerAnalysisService $manager,
        private EngineeringWorkflowLockInterface $lock,
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

        $plan = $this->manager->prepare($featureId, $this->features->request($featureId));
        $engineeringRunId = $this->lock->synchronized(
            $featureId,
            fn (): string => $this->agentRuns->start($workflow->id(), $plan->task, $correlationId),
        );

        // External LLM work deliberately runs outside the feature lock.
        try {
            $analysis = $this->manager->execute($plan, $organizationId, $correlationId);
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                fn () => $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage()),
            );
            throw $error;
        }

        $next = $this->lock->synchronized(
            $featureId,
            function () use ($featureId, $workflow, $analysis, $engineeringRunId) {
                $current = $this->workflows->get($workflow->id());
                if ($current->currentState() !== EngineeringWorkflowState::ANALYSIS) {
                    throw new WorkflowAlreadyRunningException('Engineering workflow changed while Manager analysis was running.');
                }

                $this->agentRuns->complete($engineeringRunId, $analysis->run);

                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::FEATURE_SPEC,
                    $analysis->featureSpecification['feature'],
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::ENGINEERING_MANAGER->value,
                );
                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::CONTEXT_MAP,
                    $analysis->contextMap->toArray(),
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::ENGINEERING_MANAGER->value,
                );
                $this->tasks->createFromManager(
                    $featureId,
                    is_array($analysis->featureSpecification['tasks'] ?? null) ? $analysis->featureSpecification['tasks'] : [],
                );
                $this->features->applyManagerAnalysis(
                    $featureId,
                    $analysis->featureSpecification,
                    $analysis->contextMap->toArray(),
                    $analysis->contextMap->repositoryRevision,
                );

                $next = $this->coordinator->acceptAgentResult(
                    $current,
                    AgentRole::ENGINEERING_MANAGER,
                    $analysis->run->structuredOutput,
                );
                $this->persistTransitions($current, $next->transitions);
                $this->features->updateStatus($featureId, $current->currentState()->value);

                if ($next->type === WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                    $questions = is_array($analysis->featureSpecification['open_questions'] ?? null)
                        ? $analysis->featureSpecification['open_questions']
                        : [];
                    $this->humanDecisions->create(
                        featureId: $featureId,
                        workflowId: $current->id(),
                        type: 'PRODUCT_AMBIGUITY',
                        question: 'Engineering Manager requires a human decision before workflow continuation.',
                        reason: $next->reason,
                        options: $questions,
                        evidence: [
                            'manager_decision' => $analysis->featureSpecification['decision'] ?? [],
                            'risks' => $analysis->featureSpecification['risks'] ?? [],
                        ],
                        blocking: true,
                    );
                }

                return $next;
            },
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
