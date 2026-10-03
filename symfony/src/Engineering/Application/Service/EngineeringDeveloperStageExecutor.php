<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringDeveloperStageExecutor
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
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
            return $this->requireRepositoryConfiguration($featureId, $workflowId);
        }

        $featureSpec = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $architecture = $this->requiredArtifact($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $implementation = $this->requiredArtifact($featureId, ArtifactType::IMPLEMENTATION_PLAN);
        $contextMap = $this->requiredArtifact($featureId, ArtifactType::CONTEXT_MAP);
        $previousReview = $this->artifacts->latest($featureId, ArtifactType::REVIEW_REPORT);
        $previousQa = $this->artifacts->latest($featureId, ArtifactType::QA_REPORT);

        $baseRevision = trim((string) ($contextMap['content']['repository_revision'] ?? ''));
        if ($baseRevision === '' || $baseRevision === 'unknown') {
            $baseRevision = $this->repository->currentBaseRevision();
        }

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::DEVELOPER,
            objective: 'Implement the approved Feature Specification and Architecture Decision as a bounded repository change set.',
            inputs: [
                'feature_spec' => $featureSpec['content'],
                'architecture_decision' => $architecture['content'],
                'implementation_plan' => $implementation['content'],
                'context_map' => $contextMap['content'],
                'tasks' => $this->tasks->forFeature($featureId),
                'previous_review' => $previousReview['content'] ?? null,
                'previous_qa' => $previousQa['content'] ?? null,
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$implementation['id'],
                'artifact:'.$contextMap['id'],
            ],
            constraints: [
                'Implement only approved scope.',
                'Return complete file content for CREATE/UPDATE operations.',
                'Do not modify .git, .env, vendor, node_modules or runtime var directories.',
                'Do not claim tests were executed by the runtime; CI is authoritative.',
                'Do not merge or deploy.',
            ],
            expectedOutputSchema: 'developer-result-v0.1',
            completionCriteria: [
                'Every repository change is explicit and bounded.',
                'Changed files correspond to approved scope.',
                'Implementation summary maps to acceptance criteria.',
                'Known limitations are explicit.',
            ],
            idempotencyKey: $featureId.':developer:'.$logicalAttempt.':'.$baseRevision.':'.$architecture['content_hash'],
            inputSnapshot: [
                'feature_id' => $featureId,
                'base_revision' => $baseRevision,
                'feature_spec_artifact_id' => $featureSpec['id'],
                'architecture_artifact_id' => $architecture['id'],
                'implementation_plan_artifact_id' => $implementation['id'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) {
                throw new WorkflowAlreadyRunningException('Developer stage can run only from DEVELOPMENT_RUNNING.');
            }
            $this->tasks->markRole($featureId, AgentRole::DEVELOPER, 'RUNNING');
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') {
                throw new RuntimeException('Developer Agent did not complete: '.($run->error ?? $run->status));
            }
            $this->validator->validate(AgentRole::DEVELOPER, $run->structuredOutput);

            if (($run->structuredOutput['status'] ?? null) === 'COMPLETED') {
                $branch = 'engineering/'.$featureId;
                $mutation = $this->repository->commitChanges(
                    baseRevision: $baseRevision,
                    branch: $branch,
                    changes: $run->structuredOutput['changes'],
                    message: trim((string) ($run->structuredOutput['commit_message'] ?? '')) !== ''
                        ? (string) $run->structuredOutput['commit_message']
                        : 'feat(engineering): implement '.$this->features->view($featureId)['title'],
                );

                $pullRequest = $this->repository->openPullRequest(
                    branch: $mutation['branch'],
                    title: trim((string) ($run->structuredOutput['pull_request_title'] ?? '')) !== ''
                        ? (string) $run->structuredOutput['pull_request_title']
                        : 'Engineering: '.$this->features->view($featureId)['title'],
                    body: trim((string) ($run->structuredOutput['pull_request_body'] ?? '')) !== ''
                        ? (string) $run->structuredOutput['pull_request_body']
                        : 'Autonomous COS Engineering implementation for feature '.$featureId.'. Human merge remains mandatory.',
                );

                $effectiveOutput = array_merge($run->structuredOutput, [
                    'repository_revision' => $mutation['revision'],
                    'branch' => $mutation['branch'],
                    'pull_request' => $pullRequest['number'],
                    'pull_request_url' => $pullRequest['url'],
                    'changed_files' => $mutation['changed_files'],
                    'tests_run' => [],
                ]);
                $run = new EngineeringAgentRunResult(
                    runId: $run->runId,
                    role: $run->role,
                    status: $run->status,
                    structuredOutput: $effectiveOutput,
                    provider: $run->provider,
                    model: $run->model,
                    usage: $run->usage,
                    error: $run->error,
                );
            }
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                function () use ($featureId, $engineeringRunId, $error): void {
                    $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage());
                    $this->tasks->markRole($featureId, AgentRole::DEVELOPER, 'FAILED', ['error' => $error->getMessage()]);
                },
            );
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while Developer was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            $developerStatus = (string) ($run->structuredOutput['status'] ?? '');
            $this->tasks->markRole(
                $featureId,
                AgentRole::DEVELOPER,
                $developerStatus === 'COMPLETED' ? 'COMPLETED' : ($developerStatus === 'BLOCKED' ? 'BLOCKED' : 'FAILED'),
                ['status' => $developerStatus, 'revision' => $run->structuredOutput['repository_revision'] ?? null],
            );
            $this->artifacts->createVersion(
                $featureId,
                ArtifactType::DEVELOPMENT_RESULT,
                $run->structuredOutput,
                agentRunId: $engineeringRunId,
                createdByAgent: AgentRole::DEVELOPER->value,
            );

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::DEVELOPER,
                $run->structuredOutput,
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $next;
        });
    }

    private function requireRepositoryConfiguration(string $featureId, string $workflowId): WorkflowDirective
    {
        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            $next = $this->coordinator->requireHumanDecision(
                $workflow,
                'GitHub repository credentials are required before Developer can mutate the repository.',
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            $this->humanDecisions->create(
                featureId: $featureId,
                workflowId: $workflowId,
                type: 'EXTERNAL_CREDENTIAL',
                question: 'Configure the Engineering GitHub repository/token before resuming Developer.',
                reason: $next->reason,
                options: [
                    ['id' => 'CONFIGURED', 'description' => 'Credentials have been configured; resume Developer.'],
                    ['id' => 'CANCEL', 'description' => 'Do not continue this engineering workflow.'],
                ],
                evidence: ['required_environment' => ['COS_ENGINEERING_GITHUB_REPOSITORY','COS_ENGINEERING_GITHUB_TOKEN']],
                blocking: true,
                recommendedOption: 'CONFIGURED',
            );
            return $next;
        });
    }

    private function requiredArtifact(string $featureId, ArtifactType $type): array
    {
        $artifact = $this->artifacts->latest($featureId, $type);
        if ($artifact === null) throw new RuntimeException('Developer missing required artifact '.$type->value.'.');
        return $artifact;
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
