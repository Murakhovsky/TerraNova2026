<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Context\EngineeringStandardsProvider;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
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
        private EngineeringStandardsProvider $standards,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringExecutionJournal $journal,
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
        $domainContext = $this->artifacts->latest($featureId, ArtifactType::DOMAIN_CONTEXT_PACK);

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
        $humanDecisionHistory = $this->answeredHumanDecisions($featureId);

        $this->assertArchitectureGate($architecture, $developerHandoff);

        $baseRevision = trim((string) ($architecture['content']['repository_revision'] ?? ''));
        if ($baseRevision === '' || $baseRevision === 'unknown') {
            throw new RuntimeException('Developer requires Architecture Decision bound to a repository revision.');
        }

        if ($previousDevelopment === null) {
            $currentBaseRevision = $this->journal->around(
                $featureId,
                $workflowId,
                'REPOSITORY',
                'repository.current_base_revision',
                'Verify repository revision before development',
                $correlationId,
                fn (): string => $this->repository->currentBaseRevision(),
                details: static fn (string $revision): array => ['revision' => $revision],
            );
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
        $repositoryFiles = $this->journal->around(
            $featureId,
            $workflowId,
            'REPOSITORY',
            'repository.read_files',
            'Read bounded developer repository context',
            $correlationId,
            fn (): array => $this->repository->filesAtRevision($contextPaths, $workingRevision),
            details: static fn (array $files): array => [
                'revision' => $workingRevision,
                'requested_paths' => $contextPaths,
                'returned_files' => array_values(array_filter(array_map(
                    static fn (mixed $file): ?string => is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : null,
                    $files,
                ))),
            ],
        );

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
                'engineering_standards' => $this->standards->all(),
                'developer_handoff' => $developerHandoff['content'],
                'architecture_documentation' => $architectureDocumentation['content'],
                'pending_architecture_documentation' => $pendingArchitectureDocumentation,
                'context_map' => $contextMap['content'],
                'domain_context_pack' => $domainContext['content'] ?? null,
                'repository_state' => [
                    'architecture_base_revision' => $baseRevision,
                    'working_revision' => $workingRevision,
                ],
                'repository_files' => $repositoryFiles,
                'tasks' => $this->tasks->forFeature($featureId),
                'previous_review' => $previousReview['content'] ?? null,
                'previous_qa' => $previousQa['content'] ?? null,
                'human_decisions' => $humanDecisionHistory,
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$implementation['id'],
                'artifact:'.$testPlan['id'],
                'artifact:'.$developerHandoff['id'],
                'artifact:'.$architectureDocumentation['id'],
                'artifact:'.$contextMap['id'],
                ...($domainContext !== null ? ['artifact:'.$domainContext['id']] : []),
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
                    'When DOMAIN_CONTEXT_PACK is present, obey its Architecture Constitution, contract snapshot and path policy exactly.',
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
                'domain_context_pack_hash' => $domainContext['content_hash'] ?? null,
                'domain_architecture_version' => $domainContext['content']['architecture_version'] ?? null,
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
                $this->assertDomainPathPolicy($developerChanges, $domainContext['content'] ?? null);
                $this->assertDeveloperChangeEvidence($developerChanges, $implementation['content'], $repositoryFiles);
                $changes = $this->mergeChanges(
                    $pendingArchitectureDocumentation,
                    $developerChanges,
                );
                $commitMessage = trim((string) ($run->structuredOutput['commit_message'] ?? '')) !== ''
                    ? (string) $run->structuredOutput['commit_message']
                    : 'feat(engineering): implement '.$this->features->view($featureId)['title'];
                $mutation = $this->journal->around(
                    $featureId,
                    $workflowId,
                    'GIT',
                    'repository.commit_changes',
                    'Commit Developer change set',
                    $correlationId,
                    fn (): array => $this->repository->commitChanges(
                        baseRevision: $workingRevision,
                        branch: $branch,
                        changes: $changes,
                        message: $commitMessage,
                    ),
                    $engineeringRunId,
                    static fn (array $result): array => [
                        'branch' => $result['branch'] ?? null,
                        'revision' => $result['revision'] ?? null,
                        'changed_files' => $result['changed_files'] ?? [],
                        'change_count' => count($changes),
                    ],
                );

                $pullRequestTitle = trim((string) ($run->structuredOutput['pull_request_title'] ?? '')) !== ''
                    ? (string) $run->structuredOutput['pull_request_title']
                    : 'Engineering: '.$this->features->view($featureId)['title'];
                $pullRequestBody = trim((string) ($run->structuredOutput['pull_request_body'] ?? '')) !== ''
                    ? (string) $run->structuredOutput['pull_request_body']
                    : 'Autonomous COS Engineering implementation for feature '.$featureId.'. Human merge remains mandatory.';
                $pullRequest = $this->journal->around(
                    $featureId,
                    $workflowId,
                    'GIT',
                    'repository.open_pull_request',
                    'Open or resolve Developer pull request',
                    $correlationId,
                    fn (): array => $this->repository->openPullRequest(
                        branch: $mutation['branch'],
                        title: $pullRequestTitle,
                        body: $pullRequestBody,
                    ),
                    $engineeringRunId,
                    static fn (array $result): array => [
                        'number' => $result['number'] ?? null,
                        'url' => $result['url'] ?? null,
                        'title' => $result['title'] ?? null,
                        'branch' => $mutation['branch'] ?? null,
                    ],
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
                    steps: $run->steps,
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

            if ($next->type === \App\Engineering\Application\Workflow\WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                $developerStatus = (string) ($run->structuredOutput['status'] ?? '');
                $this->humanDecisions->create(
                    featureId: $featureId,
                    workflowId: $workflow->id(),
                    type: $developerStatus === 'SECURITY_REVIEW_REQUIRED' ? 'SECURITY_DECISION' : 'SPECIFICATION_DECISION',
                    question: $developerStatus === 'SECURITY_REVIEW_REQUIRED'
                        ? 'Developer requires a human security decision before implementation can continue.'
                        : 'Developer requires a human specification decision before implementation can continue.',
                    reason: $next->reason,
                    options: [
                        ['id' => 'CONTINUE', 'description' => 'Decision/evidence is supplied; rerun Developer.'],
                        ['id' => 'CANCEL', 'description' => 'Cancel this engineering workflow.'],
                    ],
                    evidence: [
                        'requested_by_agent' => AgentRole::DEVELOPER->value,
                        'resume_state' => $workflow->resumeState()?->value,
                        'developer_status' => $developerStatus,
                        'preflight' => $run->structuredOutput['preflight'] ?? [],
                        'findings' => $run->structuredOutput['findings'] ?? [],
                        'follow_up_required' => $run->structuredOutput['follow_up_required'] ?? [],
                    ],
                    blocking: true,
                    recommendedOption: 'CONTINUE',
                );
            }
            return $next;
        });
    }

    /** @return list<array<string,mixed>> */
    private function answeredHumanDecisions(string $featureId): array
    {
        return array_slice(array_values(array_filter(
            $this->humanDecisions->historyForFeature($featureId),
            static fn (array $decision): bool =>
                ($decision['status'] ?? null) === 'ANSWERED'
                && ($decision['evidence']['requested_by_agent'] ?? null) === AgentRole::DEVELOPER->value,
        )), -20);
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

    /** @param list<array<string,mixed>> $changes @param array<string,mixed>|null $domainContext */
    private function assertDomainPathPolicy(array $changes, ?array $domainContext): void
    {
        if ($domainContext === null) return;
        $policy = is_array($domainContext['path_policy'] ?? null) ? $domainContext['path_policy'] : [];
        $owned = $this->normalizedPolicyPaths($policy['owned_paths'] ?? []);
        $shared = $this->normalizedPolicyPaths($policy['shared_paths'] ?? []);
        $forbidden = $this->normalizedPolicyPaths($policy['forbidden_paths'] ?? []);
        $allowed = array_values(array_unique(array_merge($owned, $shared)));

        foreach ($changes as $change) {
            if (!is_array($change)) continue;
            $path = str_replace('\\', '/', trim((string) ($change['path'] ?? '')));
            foreach ($forbidden as $prefix) {
                if ($this->pathMatchesPolicy($path, $prefix)) {
                    throw new RuntimeException('Developer change violates Domain forbidden path policy: '.$path);
                }
            }
            if ($allowed === []) {
                throw new RuntimeException('Domain feature has no owned/shared path policy; repository mutation is blocked.');
            }
            $matched = false;
            foreach ($allowed as $prefix) {
                if ($this->pathMatchesPolicy($path, $prefix)) { $matched = true; break; }
            }
            if (!$matched) {
                throw new RuntimeException('Developer change is outside Domain owned/shared paths: '.$path);
            }
        }
    }

    /** @return list<string> */
    private function normalizedPolicyPaths(mixed $paths): array
    {
        if (!is_array($paths)) return [];
        $out = [];
        foreach ($paths as $path) {
            if (!is_scalar($path)) continue;
            $value = rtrim(str_replace('\\', '/', trim((string) $path)), '/');
            if ($value !== '') $out[$value] = true;
        }
        return array_keys($out);
    }

    private function pathMatchesPolicy(string $path, string $policyPath): bool
    {
        return $path === $policyPath || str_starts_with($path, rtrim($policyPath, '/').'/');
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
                evidence: [
                    'requested_by_agent' => AgentRole::DEVELOPER->value,
                    'resume_state' => $workflow->resumeState()?->value,
                    'required_environment' => ['COS_ENGINEERING_GITHUB_REPOSITORY','COS_ENGINEERING_GITHUB_TOKEN'],
                ],
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
