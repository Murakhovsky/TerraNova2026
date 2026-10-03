<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Application\Workflow\WorkflowCounters;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringReviewerStageExecutor
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringFindingStoreInterface $findings,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringRepositoryGatewayInterface $repository,
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
        if (!$this->repository->available()) {
            throw new RuntimeException('Reviewer requires configured GitHub repository access.');
        }

        $featureSpec = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $architecture = $this->requiredArtifact($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $development = $this->requiredArtifact($featureId, ArtifactType::DEVELOPMENT_RESULT);
        $pullRequest = (int) ($development['content']['pull_request'] ?? 0);
        $revision = trim((string) ($development['content']['repository_revision'] ?? ''));
        if ($pullRequest <= 0 || $revision === '') {
            throw new RuntimeException('Reviewer requires Developer pull request and repository revision.');
        }

        $diff = $this->repository->pullRequestFiles($pullRequest);
        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::REVIEWER,
            objective: 'Review the actual pull request diff against Feature Specification, Architecture Decision and acceptance criteria.',
            inputs: [
                'feature_spec' => $featureSpec['content'],
                'architecture_decision' => $architecture['content'],
                'development_result' => $development['content'],
                'pull_request_files' => $diff,
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$development['id'],
                'pull_request:'.$pullRequest,
            ],
            constraints: [
                'Do not modify repository content.',
                'Review the actual diff, not Developer claims.',
                'Critical security, tenant, auth, data-loss or migration findings block approval.',
                'Every acceptance criterion must have evidence.',
            ],
            expectedOutputSchema: 'reviewer-result-v0.1',
            completionCriteria: [
                'Reviewed revision equals Developer revision.',
                'Findings have category, severity, location, evidence and suggested fix.',
                'Acceptance criteria are individually evaluated.',
                'Architecture compliance is explicit.',
            ],
            idempotencyKey: $featureId.':reviewer:'.$logicalAttempt.':'.$revision,
            inputSnapshot: [
                'feature_id' => $featureId,
                'repository_revision' => $revision,
                'pull_request' => $pullRequest,
                'feature_spec_hash' => $featureSpec['content_hash'],
                'architecture_hash' => $architecture['content_hash'],
                'development_result_hash' => $development['content_hash'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::REVIEW_PENDING) {
                throw new WorkflowAlreadyRunningException('Reviewer stage can run only from REVIEW_PENDING.');
            }
            $this->tasks->markRole($featureId, AgentRole::REVIEWER, 'RUNNING');
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') {
                throw new RuntimeException('Reviewer Agent did not complete: '.($run->error ?? $run->status));
            }
            $this->validator->validate(AgentRole::REVIEWER, $run->structuredOutput);
            if (($run->structuredOutput['reviewed_revision'] ?? null) !== $revision) {
                throw new RuntimeException('Reviewer output revision does not match Developer revision.');
            }
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                function () use ($featureId, $engineeringRunId, $error): void {
                    $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage());
                    $this->tasks->markRole($featureId, AgentRole::REVIEWER, 'FAILED', ['error' => $error->getMessage()]);
                },
            );
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::REVIEW_PENDING) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while Reviewer was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            $reviewStatus = (string) ($run->structuredOutput['status'] ?? '');
            $this->tasks->markRole(
                $featureId,
                AgentRole::REVIEWER,
                $reviewStatus === 'BLOCKED' ? 'BLOCKED' : 'COMPLETED',
                ['status' => $reviewStatus],
            );
            if (($run->structuredOutput['status'] ?? null) === 'APPROVED') {
                $this->findings->resolveOpenForSource($featureId, AgentRole::REVIEWER, $engineeringRunId);
            }
            $this->findings->recordFindings(
                featureId: $featureId,
                sourceRole: AgentRole::REVIEWER,
                findings: is_array($run->structuredOutput['findings'] ?? null) ? $run->structuredOutput['findings'] : [],
                agentRunId: $engineeringRunId,
            );
            $this->artifacts->createVersion(
                $featureId,
                ArtifactType::REVIEW_REPORT,
                $run->structuredOutput,
                agentRunId: $engineeringRunId,
                createdByAgent: AgentRole::REVIEWER->value,
            );

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::REVIEWER,
                $run->structuredOutput,
                $this->counters($featureId),
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $next;
        });
    }

    private function counters(string $featureId): WorkflowCounters
    {
        $developer = 0;
        $reviewer = 0;
        $qa = 0;
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            match ($run['role'] ?? null) {
                AgentRole::DEVELOPER->value => ++$developer,
                AgentRole::REVIEWER->value => ++$reviewer,
                AgentRole::QA->value => ++$qa,
                default => null,
            };
        }
        return new WorkflowCounters(max(0, $developer - 1), $reviewer, $qa);
    }

    private function requiredArtifact(string $featureId, ArtifactType $type): array
    {
        $artifact = $this->artifacts->latest($featureId, $type);
        if ($artifact === null) throw new RuntimeException('Reviewer missing required artifact '.$type->value.'.');
        return $artifact;
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
