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
        $testPlan = $this->requiredArtifact($featureId, ArtifactType::TEST_PLAN);
        $developerHandoff = $this->artifacts->latest($featureId, ArtifactType::DEVELOPER_HANDOFF);
        $architectureDocumentation = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DOCUMENTATION);
        $contextMap = $this->requiredArtifact($featureId, ArtifactType::CONTEXT_MAP);

        if ($developerHandoff === null || $architectureDocumentation === null) {
            return $this->requireArchitectureRevalidation(
                $featureId,
                $workflowId,
                'Architecture artifacts predate Principal Architect V0.1 handoff contract and must be revalidated.',
            );
        }
        $previousReview = $this->artifacts->latest($featureId, ArtifactType::REVIEW_REPORT);
        $previousQa = $this->artifacts->latest($featureId, ArtifactType::QA_REPORT);
        $previousDevelopment = $this->artifacts->latest($featureId, ArtifactType::DEVELOPMENT_RESULT);

        $this->assertArchitectureGate($architecture, $developerHandoff);

        $baseRevision = trim((string) ($architecture['content']['repository_revision'] ?? ''));
        if ($baseRevision === '' || $baseRevision === 'unknown') {
            throw new RuntimeException('Developer requires Architecture Decision bound to a repository revision.');
        }

        if ($previousDevelopment === null) {
            $currentBaseRevision = $this->repository->currentBaseRevision();
            if ($currentBaseRevision !== $baseRevision) {
                return $this->requireArchitectureRevalidation(
                    $featureId,
                    $workflowId,
                    sprintf(
                        'Architecture was approved for repository revision %s but main is now %s.',
                        $baseRevision,
                        $currentBaseRevision,
                    ),
                );
            }
        }

        $workingRevision = $baseRevision;
        if ($previousDevelopment !== null) {
            $previousRevision = trim((string) ($previousDevelopment['content']['repository_revision'] ?? ''));
            if ($previousRevision !== '') $workingRevision = $previousRevision;
        }
        $reviewedRevision = trim((string) ($previousReview['content']['reviewed_revision'] ?? ''));
        if ($reviewedRevision !== '') $workingRevision = $reviewedRevision;

        $allArchitectureDocumentation = is_array($architectureDocumentation['content']['changes'] ?? null)
            ? $architectureDocumentation['content']['changes']
            : [];
        $alreadyAppliedDocumentation = array_fill_keys(
            array_values(array_filter(
                is_array($previousDevelopment['content']['architect_documentation_applied'] ?? null)
                    ? $previousDevelopment['content']['architect_documentation_applied']
                    : [],
                static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
            )),
            true,
        );
        $pendingArchitectureDocumentation = array_values(array_filter(
            $allArchitectureDocumentation,
            static fn (mixed $change): bool =>
                is_array($change)
                && isset($change['path'])
                && is_string($change['path'])
                && !isset($alreadyAppliedDocumentation[$change['path']]),
        ));

        // Mutation targets must win the bounded context budget. Context-map discovery is fallback evidence.
        $contextPaths = [];
        foreach (is_array($implementation['content']['files_to_modify'] ?? null) ? $implementation['content']['files_to_modify'] : [] as $file) {
            if (is_string($file)) $contextPaths[] = $file;
            if (is_array($file) && isset($file['path']) && is_string($file['path'])) $contextPaths[] = $file['path'];
        }
        foreach (is_array($previousDevelopment['content']['changed_files'] ?? null) ? $previousDevelopment['content']['changed_files'] : [] as $changedPath) {
            if (is_string($changedPath)) $contextPaths[] = $changedPath;
        }
        foreach ($pendingArchitectureDocumentation as $change) {
            if (isset($change['path']) && is_string($change['path'])) $contextPaths[] = $change['path'];
        }
        foreach (is_array($contextMap['content']['files'] ?? null) ? $contextMap['content']['files'] : [] as $file) {
            if (is_array($file) && isset($file['path']) && is_string($file['path'])) $contextPaths[] = $file['path'];
        }
        $contextPaths = array_slice(array_values(array_unique($contextPaths)), 0, 20);
        $repositoryFiles = $this->repository->filesAtRevision($contextPaths, $workingRevision);

        if (($documentationError = $this->architectureDocumentationEvidenceError($pendingArchitectureDocumentation, $repositoryFiles)) !== null) {
            return $this->requireArchitectureRevalidation($featureId, $workflowId, $documentationError);
        }

        $architectDocumentationApplied = array_values(array_unique(array_merge(
            array_keys($alreadyAppliedDocumentation),
            array_values(array_map(
                static fn (array $change): string => (string) $change['path'],
                $pendingArchitectureDocumentation,
            )),
        )));

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::DEVELOPER,
            objective: 'Implement the approved Feature Specification and Architecture Decision as a bounded repository change set.',
            inputs: [
                'feature_spec' => $featureSpec['content'],
                'architecture_decision' => $architecture['content'],
                'implementation_plan' => $implementation['content'],
                'qa_test_plan' => $testPlan['content'],
                'developer_handoff' => $developerHandoff['content'],
                'architecture_documentation' => $architectureDocumentation['content'],
                'pending_architecture_documentation' => $pendingArchitectureDocumentation,
                'context_map' => $contextMap['content'],
                'repository_state' => [
                    'architecture_base_revision' => $baseRevision,
                    'working_revision' => $workingRevision,
                ],
                'repository_files' => $repositoryFiles,
                'tasks' => $this->tasks->forFeature($featureId),
                'previous_review' => $previousReview['content'] ?? null,
                'previous_qa' => $previousQa['content'] ?? null,
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$implementation['id'],
                'artifact:'.$testPlan['id'],
                'artifact:'.$developerHandoff['id'],
                'artifact:'.$architectureDocumentation['id'],
                'artifact:'.$contextMap['id'],
            ],
            constraints: array_values(array_merge(
                [
                    'Implement only approved scope.',
                    'Respect Principal Architect Developer Handoff and all gate conditions.',
                    'Change only files explicitly listed by Principal Architect in files_to_create/files_to_modify.',
                    'Return complete file content for CREATE/UPDATE operations.',
                    'Do not UPDATE a repository file when repository_files marks complete=false; return BLOCKED because the source context is incomplete.',
                    'Do not modify .git, .env, vendor, node_modules or runtime var directories.',
                    'Do not overwrite architecture documentation authored by Principal Architect.',
                    'Do not claim tests were executed by the runtime; CI is authoritative.',
                    'Do not merge or deploy.',
                ],
                is_array($developerHandoff['content']['mandatory_constraints'] ?? null) ? $developerHandoff['content']['mandatory_constraints'] : [],
                is_array($developerHandoff['content']['forbidden_changes'] ?? null)
                    ? array_map(static fn (mixed $item): string => 'FORBIDDEN: '.(is_scalar($item) ? (string) $item : json_encode($item)), $developerHandoff['content']['forbidden_changes'])
                    : [],
            )),
            expectedOutputSchema: 'developer-result-v0.1',
            completionCriteria: [
                'Every repository change is explicit and bounded.',
                'Changed files correspond to approved scope.',
                'Implementation summary maps to acceptance criteria.',
                'Known limitations are explicit.',
            ],
            idempotencyKey: $featureId.':developer:'.$logicalAttempt.':'.$workingRevision.':'.$architecture['content_hash'],
            inputSnapshot: [
                'feature_id' => $featureId,
                'base_revision' => $baseRevision,
                'working_revision' => $workingRevision,
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

            if (in_array((string) ($run->structuredOutput['status'] ?? ''), ['COMPLETED','COMPLETED_WITH_LIMITATIONS'], true)) {
                $branch = 'engineering/'.$featureId;
                $developerChanges = is_array($run->structuredOutput['changes'] ?? null) ? $run->structuredOutput['changes'] : [];
                $this->assertDeveloperChangeEvidence($developerChanges, $implementation['content'], $repositoryFiles);
                $changes = $this->mergeChanges(
                    $pendingArchitectureDocumentation,
                    $developerChanges,
                );
                $mutation = $this->repository->commitChanges(
                    baseRevision: $workingRevision,
                    branch: $branch,
                    changes: $changes,
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
                    'architect_documentation_applied' => $architectDocumentationApplied,
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
                    technicalRetries: $run->technicalRetries,
                );
            }
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                function () use ($featureId, $engineeringRunId, $error): void {
                    $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage(), $error instanceof \App\Engineering\Application\Agent\EngineeringAgentTechnicalFailureException ? $error->technicalRetries : 0);
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
                in_array($developerStatus, ['COMPLETED','COMPLETED_WITH_LIMITATIONS'], true) ? 'COMPLETED' : ($developerStatus === 'BLOCKED' ? 'BLOCKED' : 'FAILED'),
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

    private function assertArchitectureGate(array $architecture, array $developerHandoff): void
    {
        $gate = (string) ($architecture['content']['gate_status'] ?? '');
        $handoffGate = (string) ($developerHandoff['content']['gate_status'] ?? '');
        if (!in_array($gate, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) {
            throw new RuntimeException('Developer cannot run without an approved Architecture Gate.');
        }
        if ($handoffGate !== $gate) {
            throw new RuntimeException('Developer Handoff does not match the Architecture Gate.');
        }
    }

    private function requireArchitectureRevalidation(
        string $featureId,
        string $workflowId,
        string $reason,
    ): WorkflowDirective {
        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $reason): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            $next = $this->coordinator->revalidateArchitecture($workflow, $reason);
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $next;
        });
    }

    /**
     * @param list<array<string,mixed>> $architectureDocs
     * @param list<array{path:string,content:string,complete:bool,size:int,sha256:string}> $repositoryFiles
     */
    private function architectureDocumentationEvidenceError(array $architectureDocs, array $repositoryFiles): ?string
    {
        $evidence = [];
        foreach ($repositoryFiles as $file) {
            if (isset($file['path']) && is_string($file['path'])) $evidence[$file['path']] = $file;
        }

        foreach ($architectureDocs as $change) {
            if (!is_array($change)) return 'Principal Architect documentation change is malformed and must be revalidated.';
            $path = trim((string) ($change['path'] ?? ''));
            $operation = (string) ($change['operation'] ?? '');

            if ($operation === 'CREATE' && isset($evidence[$path])) {
                return 'Principal Architect documentation CREATE target already exists at '.$path.'; architecture must be revalidated.';
            }
            if ($operation === 'UPDATE') {
                if (!isset($evidence[$path])) {
                    return 'Principal Architect documentation UPDATE target lacks repository evidence at '.$path.'; architecture must be revalidated.';
                }
                if (($evidence[$path]['complete'] ?? false) !== true) {
                    return 'Principal Architect documentation UPDATE target is incomplete in repository context at '.$path.'; architecture must be revalidated.';
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $changes
     * @param array<string,mixed> $implementationPlan
     * @param list<array{path:string,content:string,complete:bool,size:int,sha256:string}> $repositoryFiles
     */
    private function assertDeveloperChangeEvidence(array $changes, array $implementationPlan, array $repositoryFiles): void
    {
        $plannedCreate = array_fill_keys($this->planPaths($implementationPlan['files_to_create'] ?? []), true);
        $plannedModify = array_fill_keys($this->planPaths($implementationPlan['files_to_modify'] ?? []), true);
        $evidence = [];
        foreach ($repositoryFiles as $file) {
            if (isset($file['path']) && is_string($file['path'])) $evidence[$file['path']] = $file;
        }

        foreach ($changes as $change) {
            if (!is_array($change)) throw new RuntimeException('Developer repository change must be an object.');
            $path = trim((string) ($change['path'] ?? ''));
            $operation = (string) ($change['operation'] ?? '');

            if ($operation === 'CREATE') {
                if (!isset($plannedCreate[$path])) {
                    throw new RuntimeException('Developer CREATE is outside Principal Architect implementation plan: '.$path);
                }
                if (isset($evidence[$path])) {
                    throw new RuntimeException('Developer cannot CREATE an existing repository file: '.$path);
                }
                continue;
            }

            if (!isset($plannedModify[$path])) {
                throw new RuntimeException('Developer change is outside Principal Architect files_to_modify: '.$path);
            }
            if (!isset($evidence[$path])) {
                throw new RuntimeException('Developer change lacks repository evidence: '.$path);
            }
            if ($operation === 'UPDATE' && ($evidence[$path]['complete'] ?? false) !== true) {
                throw new RuntimeException('Developer UPDATE requires complete repository source context: '.$path);
            }
        }
    }

    /** @return list<string> */
    private function planPaths(mixed $files): array
    {
        if (!is_array($files)) return [];

        $paths = [];
        foreach ($files as $file) {
            $path = is_string($file)
                ? trim($file)
                : (is_array($file) && isset($file['path']) && is_string($file['path']) ? trim($file['path']) : '');
            if ($path !== '') $paths[$path] = true;
        }

        return array_keys($paths);
    }

    /** @param list<array<string,mixed>> $architectureDocs @param list<array<string,mixed>> $developerChanges */
    private function mergeChanges(array $architectureDocs, array $developerChanges): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($architectureDocs, $developerChanges) as $change) {
            if (!is_array($change)) throw new RuntimeException('Repository change must be an object.');
            $path = trim((string) ($change['path'] ?? ''));
            if ($path === '') throw new RuntimeException('Repository change path cannot be empty.');
            if (isset($seen[$path])) {
                throw new RuntimeException('Developer attempted to overwrite Principal Architect or duplicate repository change: '.$path);
            }
            $seen[$path] = true;
            $merged[] = $change;
        }

        if ($merged === [] || count($merged) > 20) {
            throw new RuntimeException('Combined Architect/Developer change set must contain between 1 and 20 files.');
        }

        return $merged;
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
