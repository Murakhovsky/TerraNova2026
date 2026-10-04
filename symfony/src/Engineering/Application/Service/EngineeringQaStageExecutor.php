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
use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowAlreadyRunningException;
use App\Engineering\Application\Workflow\WorkflowCounters;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalEvidence;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringQaStageExecutor
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringFindingStoreInterface $findings,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringReportBuilder $reports,
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
            throw new RuntimeException('QA requires configured GitHub repository access.');
        }

        $workflow = $this->workflows->get($workflowId);
        if ($workflow->currentState() === EngineeringWorkflowState::QA_PLANNING) {
            return $this->executePlanning($featureId, $workflowId, $organizationId, $correlationId, $logicalAttempt);
        }
        if ($workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
            throw new WorkflowAlreadyRunningException('QA stage can run only from QA_PLANNING or QA_PENDING.');
        }

        return $this->executeVerification($featureId, $workflowId, $organizationId, $correlationId, $logicalAttempt);
    }

    private function executePlanning(
        string $featureId,
        string $workflowId,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt,
    ): WorkflowDirective {
        $featureSpec = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $contextMap = $this->requiredArtifact($featureId, ArtifactType::CONTEXT_MAP);

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::QA,
            objective: 'Create an independent QA Test Plan from the Feature Specification before architecture and implementation.',
            inputs: [
                'phase' => 'PLAN',
                'feature_id' => $featureId,
                'feature_spec' => $featureSpec['content'],
                'acceptance_criteria' => $featureSpec['content']['acceptance_criteria'] ?? [],
                'context_map' => $contextMap['content'],
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$contextMap['id'],
            ],
            constraints: [
                'Do not inspect or assume future implementation details.',
                'Map every acceptance criterion to concrete verification scenarios.',
                'Cover positive, negative, edge, permissions, tenant, API, database, UI, regression and performance cases where applicable.',
                'Mark required test suites explicitly.',
                'Do not mutate repository content during planning.',
            ],
            expectedOutputSchema: 'qa-result-v0.1',
            completionCriteria: [
                'phase is PLAN and status is PLAN_READY.',
                'Test Plan is bound to the feature id.',
                'Positive, negative and edge scenarios are explicit.',
                'Required suites and blocking checks are explicit.',
            ],
            idempotencyKey: $featureId.':qa-plan:'.$logicalAttempt.':'.$featureSpec['content_hash'],
            inputSnapshot: [
                'feature_id' => $featureId,
                'feature_spec_hash' => $featureSpec['content_hash'],
                'context_map_hash' => $contextMap['content_hash'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PLANNING) {
                throw new WorkflowAlreadyRunningException('QA Test Plan stage can run only from QA_PLANNING.');
            }
            $this->tasks->markRole($featureId, AgentRole::QA, 'RUNNING');
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') throw new RuntimeException('QA planning Agent did not complete: '.($run->error ?? $run->status));
            $this->validator->validate(AgentRole::QA, $run->structuredOutput);
            if (($run->structuredOutput['phase'] ?? null) !== 'PLAN' || ($run->structuredOutput['status'] ?? null) !== 'PLAN_READY') {
                throw new RuntimeException('QA planning must return PLAN/PLAN_READY.');
            }
            if (($run->structuredOutput['feature_id'] ?? null) !== $featureId) {
                throw new RuntimeException('QA Test Plan feature id does not match workflow feature.');
            }
        } catch (\Throwable $error) {
            $this->failRun($featureId, $engineeringRunId, $error);
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PLANNING) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while QA Test Plan was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            $this->tasks->markRole($featureId, AgentRole::QA, 'COMPLETED', ['status' => 'PLAN_READY', 'phase' => 'PLAN']);
            $this->artifacts->createVersion(
                $featureId,
                ArtifactType::TEST_PLAN,
                $run->structuredOutput['test_plan'],
                agentRunId: $engineeringRunId,
                createdByAgent: AgentRole::QA->value,
            );

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::QA,
                $run->structuredOutput,
                $this->counters($featureId),
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $next;
        });
    }

    private function executeVerification(
        string $featureId,
        string $workflowId,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt,
    ): WorkflowDirective {
        $featureSpec = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $architecture = $this->requiredArtifact($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $development = $this->requiredArtifact($featureId, ArtifactType::DEVELOPMENT_RESULT);
        $review = $this->requiredArtifact($featureId, ArtifactType::REVIEW_REPORT);
        $testPlan = $this->requiredArtifact($featureId, ArtifactType::TEST_PLAN);

        if (($review['content']['status'] ?? null) !== 'APPROVED') {
            throw new RuntimeException('QA execution requires an APPROVED Reviewer report.');
        }

        $revision = trim((string) ($review['content']['reviewed_revision'] ?? $development['content']['repository_revision'] ?? ''));
        $pullRequest = (int) ($review['content']['pull_request'] ?? $development['content']['pull_request'] ?? 0);
        $branch = trim((string) ($development['content']['branch'] ?? ''));
        if ($revision === '' || $pullRequest <= 0 || $branch === '') {
            throw new RuntimeException('QA execution requires reviewed revision, pull request and implementation branch.');
        }

        $ci = $this->repository->commitChecks($revision);
        if ($ci['state'] === 'PENDING') {
            return new WorkflowDirective(WorkflowDirectiveType::STOP, null, 'QA is waiting for GitHub CI to finish for revision '.$revision.'.');
        }
        $diff = $this->repository->pullRequestFiles($pullRequest);

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::QA,
            objective: 'Verify observable system behavior against the approved QA Test Plan and every Acceptance Criterion on the exact reviewed revision.',
            inputs: [
                'phase' => 'EXECUTION',
                'feature_id' => $featureId,
                'feature_spec' => $featureSpec['content'],
                'acceptance_criteria' => $featureSpec['content']['acceptance_criteria'] ?? [],
                'qa_test_plan' => $testPlan['content'],
                'architecture_decision' => $architecture['content'],
                'development_result' => $development['content'],
                'review_report' => $review['content'],
                'pull_request_files' => $diff,
                'ci_evidence' => $ci,
                'application_surfaces' => [
                    'affected_areas' => $featureSpec['content']['affected_areas'] ?? [],
                    'changed_files' => $development['content']['changed_files'] ?? [],
                    'api_changes' => $development['content']['api_changes'] ?? [],
                    'database_changes' => $development['content']['database_changes'] ?? [],
                ],
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$testPlan['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$development['id'],
                'artifact:'.$review['id'],
                'pull_request:'.$pullRequest,
                'commit:'.$revision,
            ],
            constraints: [
                'Verify behavior, not code aesthetics.',
                'Use concrete evidence for every PASS.',
                'Do not modify production implementation.',
                'QA-authored repository changes are allowed only under tests/ or symfony/tests/.',
                'If automated tests are added, return TESTS_UPDATED so Reviewer re-checks the new revision.',
                'Do not report PASS when deterministic CI evidence failed.',
                'Evaluate every Feature Specification acceptance criterion.',
                'Check all applicable COS system invariants.',
            ],
            expectedOutputSchema: 'qa-result-v0.1',
            completionCriteria: [
                'Result is bound to the reviewed revision and pull request.',
                'Every acceptance criterion is PASS or FAIL with concrete evidence.',
                'Applicable COS invariants are evidenced.',
                'Regression and defect results are explicit.',
                'Any QA-authored test mutation stays inside approved test roots.',
            ],
            idempotencyKey: $featureId.':qa-exec:'.$logicalAttempt.':'.$revision,
            inputSnapshot: [
                'feature_id' => $featureId,
                'repository_revision' => $revision,
                'pull_request' => $pullRequest,
                'feature_spec_hash' => $featureSpec['content_hash'],
                'test_plan_hash' => $testPlan['content_hash'],
                'review_report_hash' => $review['content_hash'],
                'ci_state' => $ci['state'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
                throw new WorkflowAlreadyRunningException('QA execution can run only from QA_PENDING.');
            }
            $this->tasks->markRole($featureId, AgentRole::QA, 'RUNNING');
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') throw new RuntimeException('QA Agent did not complete: '.($run->error ?? $run->status));
            $this->validator->validate(AgentRole::QA, $run->structuredOutput);
            if (($run->structuredOutput['phase'] ?? null) !== 'EXECUTION') throw new RuntimeException('QA execution must return EXECUTION phase.');
            if (($run->structuredOutput['feature_id'] ?? null) !== $featureId) throw new RuntimeException('QA output feature id does not match workflow feature.');
            if (($run->structuredOutput['tested_revision'] ?? null) !== $revision) throw new RuntimeException('QA output revision does not match reviewed revision.');

            $effective = $run->structuredOutput;
            $effective['ci_evidence'] = $ci;

            if (($effective['status'] ?? null) === 'TESTS_UPDATED') {
                $mutation = $this->repository->commitChanges(
                    baseRevision: $revision,
                    branch: $branch,
                    changes: $effective['test_changes'],
                    message: 'test(engineering): add QA coverage for '.$this->features->view($featureId)['title'],
                );
                $effective['repository_revision_after_tests'] = $mutation['revision'];
            } elseif ($ci['state'] === 'FAILED') {
                $effective['status'] = 'FAIL';
                $effective['tests']['total'] = max((int) ($effective['tests']['total'] ?? 0), $ci['total']);
                $effective['tests']['failed'] = max((int) ($effective['tests']['failed'] ?? 0), $ci['failed']);
                $effective['tests']['passed'] = min(
                    (int) ($effective['tests']['passed'] ?? 0),
                    max(0, $effective['tests']['total'] - $effective['tests']['failed'] - (int) ($effective['tests']['skipped'] ?? 0)),
                );
                $effective['defects'][] = [
                    'category' => 'TESTS',
                    'severity' => 'MAJOR',
                    'title' => 'GitHub CI failed',
                    'description' => 'Deterministic CI evidence failed for the tested revision.',
                    'evidence' => $ci['checks'],
                ];
            }

            $run = new EngineeringAgentRunResult(
                runId: $run->runId,
                role: $run->role,
                status: $run->status,
                structuredOutput: $effective,
                provider: $run->provider,
                model: $run->model,
                usage: $run->usage,
                error: $run->error,
                technicalRetries: $run->technicalRetries,
            );
        } catch (\Throwable $error) {
            $this->failRun($featureId, $engineeringRunId, $error);
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId, $ci, $featureSpec, $architecture, $development, $review): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while QA was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            $qaStatus = (string) ($run->structuredOutput['status'] ?? '');
            $this->tasks->markRole(
                $featureId,
                AgentRole::QA,
                $qaStatus === 'BLOCKED' ? 'BLOCKED' : 'COMPLETED',
                ['status' => $qaStatus, 'phase' => 'EXECUTION'],
            );

            if ($qaStatus === 'PASS') $this->findings->resolveOpenForSource($featureId, AgentRole::QA, $engineeringRunId);
            $findings = array_merge(
                is_array($run->structuredOutput['defects'] ?? null) ? $run->structuredOutput['defects'] : [],
                is_array($run->structuredOutput['security_findings'] ?? null) ? $run->structuredOutput['security_findings'] : [],
            );
            $this->findings->recordFindings(
                featureId: $featureId,
                sourceRole: AgentRole::QA,
                findings: $findings,
                agentRunId: $engineeringRunId,
            );
            $this->artifacts->createVersion(
                $featureId,
                ArtifactType::QA_REPORT,
                $run->structuredOutput,
                agentRunId: $engineeringRunId,
                createdByAgent: AgentRole::QA->value,
            );

            $ready = new ReadyForHumanApprovalEvidence(
                architectureApproved: in_array((string) ($architecture['content']['status'] ?? ''), ['APPROVED','APPROVED_WITH_CONDITIONS'], true),
                developmentCompleted: in_array((string) ($development['content']['status'] ?? ''), ['COMPLETED','COMPLETED_WITH_LIMITATIONS'], true),
                reviewApproved: ($review['content']['status'] ?? null) === 'APPROVED',
                qaPassed: $qaStatus === 'PASS',
                ciPassed: $ci['state'] === 'SUCCESS',
                allBlockingAcceptanceCriteriaVerified: $this->acceptanceCriteriaVerified($featureSpec['content'], $run->structuredOutput),
                hasOpenCriticalFinding: $this->findings->hasOpenCritical($featureId),
                hasBlockingHumanDecision: $this->humanDecisions->openForFeature($featureId) !== [],
                hasRunningTask: $this->tasks->hasIncomplete($featureId),
            );

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::QA,
                $run->structuredOutput,
                $this->counters($featureId),
                $ready,
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);

            if ($workflow->currentState() === EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::FINAL_REPORT,
                    $this->reports->build(
                        featureId: $featureId,
                        workflowId: $workflowId,
                        status: EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL->value,
                        recommendation: EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL->value,
                    ),
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::QA->value,
                );
            }

            return $next;
        });
    }

    private function acceptanceCriteriaVerified(array $featureSpec, array $qa): bool
    {
        $results = [];
        foreach (is_array($qa['acceptance_criteria'] ?? null) ? $qa['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) continue;
            $results[(string) ($criterion['id'] ?? '')] = (string) ($criterion['status'] ?? '');
        }

        foreach (is_array($featureSpec['acceptance_criteria'] ?? null) ? $featureSpec['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) return false;
            $id = (string) ($criterion['id'] ?? '');
            $blocking = !array_key_exists('blocking', $criterion) || (bool) $criterion['blocking'];
            if ($blocking && ($id === '' || ($results[$id] ?? null) !== 'PASS')) return false;
        }
        return true;
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
        return new WorkflowCounters(max(0, $developer - 1), $reviewer, max(0, $qa - 1));
    }

    private function failRun(string $featureId, string $engineeringRunId, \Throwable $error): void
    {
        $this->lock->synchronized(
            $featureId,
            function () use ($featureId, $engineeringRunId, $error): void {
                $this->agentRuns->fail(
                    $engineeringRunId,
                    'TASK_ERROR',
                    $error->getMessage(),
                    $error instanceof \App\Engineering\Application\Agent\EngineeringAgentTechnicalFailureException ? $error->technicalRetries : 0,
                );
                $this->tasks->markRole($featureId, AgentRole::QA, 'FAILED', ['error' => $error->getMessage()]);
            },
        );
    }

    private function requiredArtifact(string $featureId, ArtifactType $type): array
    {
        $artifact = $this->artifacts->latest($featureId, $type);
        if ($artifact === null) throw new RuntimeException('QA missing required artifact '.$type->value.'.');
        return $artifact;
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
