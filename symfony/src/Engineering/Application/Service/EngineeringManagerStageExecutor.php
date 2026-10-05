<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Manager\EngineeringManagerAnalysisService;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

final readonly class EngineeringManagerStageExecutor
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringManagerAnalysisService $manager,
        private EngineeringExecutionJournal $journal,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {}

    public function execute(
        string $featureId,
        string $workflowId,
        EngineeringRequest $request,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt,
    ): WorkflowDirective {
        $domainContext = $this->domainContext($request);
        $plan = $this->journal->around(
            $featureId,
            $workflowId,
            'REPOSITORY',
            'repository.discover_context',
            'Discover repository context for Engineering Manager',
            $correlationId,
            fn () => $this->manager->prepare($featureId, $request, $logicalAttempt),
            details: static fn ($plan): array => [
                'repository_revision' => $plan->contextMap->repositoryRevision,
                'files' => array_values(array_filter(array_map(
                    static fn (mixed $file): ?string => is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : null,
                    $plan->contextMap->files,
                ))),
                'agent_role' => $plan->task->role->value,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($workflowId, $plan, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) {
                throw new WorkflowAlreadyRunningException('Manager stage can run only from ANALYSIS.');
            }
            return $this->agentRuns->start($workflowId, $plan->task, $correlationId);
        });

        try {
            $analysis = $this->manager->execute($plan, $organizationId, $correlationId);
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                fn () => $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage(), $error instanceof \App\Engineering\Application\Agent\EngineeringAgentTechnicalFailureException ? $error->technicalRetries : 0),
            );
            throw $error;
        }

        return $this->lock->synchronized(
            $featureId,
            function () use ($featureId, $workflowId, $analysis, $engineeringRunId, $domainContext): WorkflowDirective {
                $workflow = $this->workflows->get($workflowId);
                if ($workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) {
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
                if ($domainContext !== null) {
                    $this->artifacts->createVersion(
                        $featureId,
                        ArtifactType::DOMAIN_CONTEXT_PACK,
                        $domainContext,
                        agentRunId: $engineeringRunId,
                        createdByAgent: 'DOMAIN_RUNTIME',
                    );
                }
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
                    $workflow,
                    AgentRole::ENGINEERING_MANAGER,
                    $analysis->run->structuredOutput,
                );
                $this->persistTransitions($workflow, $next->transitions);
                $this->features->updateStatus($featureId, $workflow->currentState()->value);

                if ($next->type === WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                    $questions = is_array($analysis->featureSpecification['open_questions'] ?? null)
                        ? $analysis->featureSpecification['open_questions']
                        : [];
                    $question = $questions[0] ?? null;
                    if (!is_array($question)) throw new \RuntimeException('Manager human decision requires one validated open question.');
                    $this->humanDecisions->create(
                        featureId: $featureId,
                        workflowId: $workflow->id(),
                        type: 'PRODUCT_AMBIGUITY',
                        question: (string) ($question['question'] ?? ''),
                        reason: $next->reason,
                        options: is_array($question['options'] ?? null) ? $question['options'] : [],
                        evidence: [
                            'requested_by_agent' => AgentRole::ENGINEERING_MANAGER->value,
                            'resume_state' => $workflow->resumeState()?->value,
                            'question_id' => $question['id'] ?? null,
                            'manager_decision' => $analysis->featureSpecification['decision'] ?? [],
                            'risks' => $analysis->featureSpecification['risks'] ?? [],
                        ],
                        blocking: true,
                        recommendedOption: isset($question['recommended_option']) && is_string($question['recommended_option']) ? $question['recommended_option'] : null,
                    );
                }

                return $next;
            },
        );
    }

    /** @return array<string,mixed>|null */
    private function domainContext(EngineeringRequest $request): ?array
    {
        foreach (array_reverse($request->previousContext) as $entry) {
            if (!is_array($entry)) continue;
            $context = $entry['domain_development'] ?? null;
            if (!is_array($context) || trim((string) ($context['domain_id'] ?? '')) === '') continue;
            return $context;
        }
        return null;
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
