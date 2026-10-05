<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Context\EngineeringStandardsProvider;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
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
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringProductRequirementsStageExecutor
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringStandardsProvider $standards,
        private EngineeringAgentRunnerInterface $agents,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringAgentOutputValidator $validator = new EngineeringAgentOutputValidator(),
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {}

    public function execute(
        string $featureId,
        string $workflowId,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt = 1,
    ): WorkflowDirective {
        $managerDraft = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $contextMap = $this->requiredArtifact($featureId, ArtifactType::CONTEXT_MAP);
        $domainContext = $this->artifacts->latest($featureId, ArtifactType::DOMAIN_CONTEXT_PACK);
        $request = $this->features->request($featureId);

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::PRODUCT_REQUIREMENTS,
            objective: 'Produce the authoritative Feature Specification from the engineering request, Manager analysis and repository context.',
            inputs: [
                'engineering_request' => [
                    'request_id' => $request->requestId,
                    'title' => $request->title,
                    'description' => $request->description,
                    'source' => ['type' => $request->sourceType, 'reference' => $request->sourceReference],
                    'priority' => $request->priority,
                    'metadata' => $request->metadata,
                    'constraints' => $request->constraints,
                    'attachments' => $request->attachments,
                    'previous_context' => $request->previousContext,
                ],
                'manager_draft' => $managerDraft['content'],
                'repository_context' => $contextMap['content'],
                'domain_context_pack' => $domainContext['content'] ?? null,
                'engineering_standards' => $this->standards->all(),
            ],
            contextRefs: [
                'artifact:'.$managerDraft['id'],
                'artifact:'.$contextMap['id'],
                ...($domainContext !== null ? ['artifact:'.$domainContext['id']] : []),
            ],
            constraints: [
                'Own WHAT is required, not HOW it is implemented.',
                'Do not write production code or make Principal Architect decisions.',
                'Do not silently invent requirements.',
                'Produce stable testable Acceptance Criteria.',
                'Treat Manager analysis as preliminary input, not as an approval authority.',
            ],
            expectedOutputSchema: 'product-requirements-result-v2.0',
            completionCriteria: [
                'Feature Specification is authoritative and testable.',
                'Scope and out-of-scope are explicit.',
                'Acceptance Criteria have stable IDs.',
                'Dependencies, risks, assumptions and open questions are explicit.',
            ],
            idempotencyKey: $featureId.':product:'.$logicalAttempt.':'.$managerDraft['content_hash'],
            inputSnapshot: [
                'feature_id' => $featureId,
                'manager_draft_hash' => $managerDraft['content_hash'],
                'context_map_hash' => $contextMap['content_hash'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) {
                throw new WorkflowAlreadyRunningException('Product / Requirements stage can run only from ANALYSIS.');
            }
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') {
                throw new RuntimeException('Product / Requirements Agent did not complete: '.($run->error ?? $run->status));
            }
            $this->validator->validate(AgentRole::PRODUCT_REQUIREMENTS, $run->structuredOutput);
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                fn () => $this->agentRuns->fail(
                    $engineeringRunId,
                    'TASK_ERROR',
                    $error->getMessage(),
                    $error instanceof \App\Engineering\Application\Agent\EngineeringAgentTechnicalFailureException ? $error->technicalRetries : 0,
                ),
            );
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId, $contextMap): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while Product / Requirements was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            $status = (string) ($run->structuredOutput['status'] ?? '');

            if ($status === 'SPECIFICATION_READY') {
                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::FEATURE_SPEC,
                    $run->structuredOutput['feature'],
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::PRODUCT_REQUIREMENTS->value,
                );
                $this->tasks->createFromManager(
                    $featureId,
                    is_array($run->structuredOutput['tasks'] ?? null) ? $run->structuredOutput['tasks'] : [],
                );
                $this->features->applyManagerAnalysis(
                    $featureId,
                    $run->structuredOutput,
                    $contextMap['content'],
                    isset($contextMap['content']['repository_revision']) ? (string) $contextMap['content']['repository_revision'] : null,
                );
            }

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::PRODUCT_REQUIREMENTS,
                $run->structuredOutput,
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);

            if ($next->type === WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                $questions = is_array($run->structuredOutput['open_questions'] ?? null) ? $run->structuredOutput['open_questions'] : [];
                $question = $questions[0] ?? null;
                if (!is_array($question)) throw new RuntimeException('Product human decision requires one validated open question.');

                $this->humanDecisions->create(
                    featureId: $featureId,
                    workflowId: $workflow->id(),
                    type: 'PRODUCT_AMBIGUITY',
                    question: (string) ($question['question'] ?? ''),
                    reason: $next->reason,
                    options: is_array($question['options'] ?? null) ? $question['options'] : [],
                    evidence: [
                        'requested_by_agent' => AgentRole::PRODUCT_REQUIREMENTS->value,
                        'resume_state' => $workflow->resumeState()?->value,
                        'question_id' => $question['id'] ?? null,
                        'product_decision' => $run->structuredOutput['decision'] ?? [],
                        'risks' => $run->structuredOutput['risks'] ?? [],
                    ],
                    blocking: true,
                    recommendedOption: isset($question['recommended_option']) && is_string($question['recommended_option'])
                        ? $question['recommended_option']
                        : null,
                );
            }

            return $next;
        });
    }

    private function requiredArtifact(string $featureId, ArtifactType $type): array
    {
        $artifact = $this->artifacts->latest($featureId, $type);
        if ($artifact === null) throw new RuntimeException('Product / Requirements missing required artifact '.$type->value.'.');
        return $artifact;
    }

    /** @param list<\App\Engineering\Domain\Workflow\WorkflowTransition> $transitions */
    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
