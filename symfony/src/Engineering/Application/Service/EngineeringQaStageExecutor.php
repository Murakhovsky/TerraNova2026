<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Context\EngineeringStandardsProvider;
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
        private EngineeringStandardsProvider $standards,
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
        $humanDecisionHistory = $this->answeredHumanDecisions($featureId);

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
                'human_decisions' => $humanDecisionHistory,
                'engineering_standards' => $this->standards->all(),
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
                'Return HUMAN_TEST_REQUIRED when a necessary verification decision/evidence cannot be automated; return BLOCKED only for a concrete non-human blocker.',
            ],
            expectedOutputSchema: 'qa-result-v0.1',
            completionCriteria: [
                'phase is PLAN and status is one of PLAN_READY, BLOCKED, HUMAN_TEST_REQUIRED.',
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
            if (($run->structuredOutput['phase'] ?? null) !== 'PLAN') {
                throw new RuntimeException('QA planning must return PLAN phase.');
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
            $planningStatus = (string) ($run->structuredOutput['status'] ?? '');
            $this->tasks->markRole(
                $featureId,
                AgentRole::QA,
                $planningStatus === 'BLOCKED' ? 'BLOCKED' : ($planningStatus === 'HUMAN_TEST_REQUIRED' ? 'BLOCKED' : 'COMPLETED'),
                ['status' => $planningStatus, 'phase' => 'PLAN'],
            );
            if ($planningStatus === 'PLAN_READY') {
                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::TEST_PLAN,
                    $run->structuredOutput['test_plan'],
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::QA->value,
                );
            }

            $next = $this->coordinator->acceptAgentResult(
                $workflow,
                AgentRole::QA,
                $run->structuredOutput,
                $this->counters($featureId),
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            if ($next->type === WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                $this->createQaHumanDecision($featureId, $workflow, $run->structuredOutput, 'QA_PLANNING');
            }
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
        $implementation = $this->requiredArtifact($featureId, ArtifactType::IMPLEMENTATION_PLAN);
        $testPlan = $this->requiredArtifact($featureId, ArtifactType::TEST_PLAN);
        $humanDecisionHistory = $this->answeredHumanDecisions($featureId);

        if (($review['content']['status'] ?? null) !== 'APPROVED') {
            throw new RuntimeException('QA execution requires an APPROVED Reviewer report.');
        }

        $revision = trim((string) ($review['content']['reviewed_revision'] ?? $development['content']['repository_revision'] ?? ''));
        $pullRequest = (int) ($review['content']['pull_request'] ?? $development['content']['pull_request'] ?? 0);
        $branch = trim((string) ($development['content']['branch'] ?? ''));
        if ($revision === '' || $pullRequest <= 0 || $branch === '') {
            throw new RuntimeException('QA execution requires reviewed revision, pull request and implementation branch.');
        }

        $pullRequestState = $this->repository->pullRequest($pullRequest);
        if (($pullRequestState['merged'] ?? false) === true || ($pullRequestState['state'] ?? null) !== 'open') {
            throw new RuntimeException('QA execution requires an open, unmerged pull request.');
        }
        if (($pullRequestState['head_revision'] ?? null) !== $revision) {
            throw new RuntimeException('QA refused stale review evidence because pull request head changed after review.');
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
                'pull_request_state' => $pullRequestState,
                'pull_request_files' => $diff,
                'ci_evidence' => $ci,
                'human_decisions' => $humanDecisionHistory,
                'engineering_standards' => $this->standards->all(),
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
            $this->assertFeatureCoverage($featureSpec['content'], $run->structuredOutput);

            $effective = $run->structuredOutput;
            $effective['ci_evidence'] = $ci;
            $effective['required_ci_checks'] = $this->requiredCiCheckNames($testPlan['content'], $implementation['content']);

            if (($effective['status'] ?? null) === 'TESTS_UPDATED') {
                $this->assertQaTestChanges($effective['test_changes'] ?? []);
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

            $this->validator->validate(AgentRole::QA, $effective);
            $this->assertFeatureCoverage($featureSpec['content'], $effective);

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

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId, $ci, $featureSpec, $architecture, $development, $review, $implementation, $testPlan, $pullRequest): WorkflowDirective {
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

            $prState = $this->repository->pullRequest($pullRequest);
            $ready = new ReadyForHumanApprovalEvidence(
                architectureApproved: in_array((string) ($architecture['content']['gate_status'] ?? $architecture['content']['status'] ?? ''), ['APPROVED','APPROVED_WITH_CONDITIONS'], true),
                developmentCompleted: in_array((string) ($development['content']['status'] ?? ''), ['COMPLETED','COMPLETED_WITH_LIMITATIONS'], true),
                reviewApproved: ($review['content']['status'] ?? null) === 'APPROVED',
                qaPassed: $qaStatus === 'PASS',
                ciPassed: $this->requiredCiChecksPassed($testPlan['content'], $implementation['content'], $ci),
                allBlockingAcceptanceCriteriaVerified: $this->acceptanceCriteriaVerified($featureSpec['content'], $run->structuredOutput),
                hasOpenCriticalFinding: $this->findings->hasOpenCritical($featureId),
                hasBlockingHumanDecision: $this->humanDecisions->openForFeature($featureId) !== [],
                hasRunningTask: $this->tasks->hasIncomplete($featureId),
                tenantIsolationVerified: $this->invariantVerified($run->structuredOutput, 'tenant_isolation'),
                authorizationVerified: $this->invariantVerified($run->structuredOutput, 'authorization'),
                authenticationVerifiedOrNotApplicable: $this->invariantVerified($run->structuredOutput, 'authentication'),
                migrationVerifiedOrNotApplicable: $this->invariantVerified($run->structuredOutput, 'migration'),
                rollbackVerifiedOrNotApplicable: $this->invariantVerified($run->structuredOutput, 'rollback'),
                apiCompatibilityVerifiedOrNotApplicable: $this->invariantVerified($run->structuredOutput, 'backward_compatibility'),
                staticAnalysisPassed: $this->staticAnalysisPassed($ci),
                requiredTestsPassed: $this->requiredSuitesPassed($testPlan['content'], $run->structuredOutput),
                smokePassed: $this->smokePassed($testPlan['content'], $run->structuredOutput),
                documentationImpactChecked: $this->documentationImpactChecked($implementation['content'], $development['content']),
                hasOpenMajorOrHigherFinding: $this->findings->hasOpenMajorOrHigher($featureId),
                revisionConsistent: ($review['content']['reviewed_revision'] ?? null) === ($run->structuredOutput['tested_revision'] ?? null)
                    && ($prState['head_revision'] ?? null) === ($run->structuredOutput['tested_revision'] ?? null),
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

            if ($next->type === WorkflowDirectiveType::REQUEST_HUMAN_DECISION) {
                $this->createQaHumanDecision($featureId, $workflow, $run->structuredOutput, 'QA_EXECUTION');
            }

            if ($workflow->currentState() === EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
                $finalReport = $this->reports->build(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    status: EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL->value,
                    recommendation: EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL->value,
                );
                $this->artifacts->createVersion(
                    $featureId,
                    ArtifactType::FINAL_REPORT,
                    $finalReport,
                    agentRunId: $engineeringRunId,
                    createdByAgent: AgentRole::QA->value,
                );
                $this->commentReadySummary($featureId, $finalReport);
            }

            return $next;
        });
    }

    private function assertFeatureCoverage(array $featureSpec, array $qa): void
    {
        $expected = [];
        foreach (is_array($featureSpec['acceptance_criteria'] ?? null) ? $featureSpec['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) throw new RuntimeException('Feature Specification acceptance criteria are malformed.');
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id === '') throw new RuntimeException('Feature Specification acceptance criterion id is missing.');
            $expected[$id] = true;
        }

        $actual = [];
        foreach (is_array($qa['acceptance_criteria'] ?? null) ? $qa['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) continue;
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id !== '') $actual[$id] = true;
        }

        $missing = array_diff_key($expected, $actual);
        $unexpected = array_diff_key($actual, $expected);
        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException(sprintf(
                'QA acceptance-criteria coverage mismatch. Missing: %s; unexpected: %s.',
                implode(', ', array_keys($missing)) ?: 'none',
                implode(', ', array_keys($unexpected)) ?: 'none',
            ));
        }
    }

    /** @return list<array<string,mixed>> */
    private function answeredHumanDecisions(string $featureId): array
    {
        return array_slice(array_values(array_filter(
            $this->humanDecisions->historyForFeature($featureId),
            static fn (array $decision): bool =>
                ($decision['status'] ?? null) === 'ANSWERED'
                && ($decision['evidence']['requested_by_agent'] ?? null) === AgentRole::QA->value,
        )), -20);
    }

    /** @param array<string,mixed> $output */
    private function createQaHumanDecision(string $featureId, WorkflowExecution $workflow, array $output, string $phase): void
    {
        $manual = is_array($output['human_tests_required'] ?? null) ? $output['human_tests_required'] : [];
        $options = $phase === 'QA_EXECUTION'
            ? [
                ['id' => 'TEST_PASSED', 'description' => 'Required manual verification passed; rerun QA with this evidence.'],
                ['id' => 'TEST_FAILED', 'description' => 'Required manual verification failed; rerun QA with this evidence.'],
                ['id' => 'CANCEL', 'description' => 'Cancel this engineering workflow.'],
            ]
            : [
                ['id' => 'CONTINUE', 'description' => 'Human supplied the requested planning decision/evidence; rerun QA planning.'],
                ['id' => 'CANCEL', 'description' => 'Cancel this engineering workflow.'],
            ];

        $this->humanDecisions->create(
            featureId: $featureId,
            workflowId: $workflow->id(),
            type: 'MANUAL_TEST_DECISION',
            question: $phase === 'QA_EXECUTION'
                ? 'QA requires manual verification evidence before the feature can pass.'
                : 'QA planning requires a human testing decision before architecture can continue.',
            reason: 'QA returned HUMAN_TEST_REQUIRED.',
            options: $options,
            evidence: [
                'requested_by_agent' => AgentRole::QA->value,
                'resume_state' => $workflow->resumeState()?->value,
                'phase' => $phase,
                'human_tests_required' => $manual,
            ],
            blocking: true,
            recommendedOption: $phase === 'QA_EXECUTION' ? null : 'CONTINUE',
        );
    }

    /** @param array<string,mixed> $report */
    private function commentReadySummary(string $featureId, array $report): void
    {
        try {
            $feature = $this->features->view($featureId);
            $issue = (int) ($feature['external_issue_id'] ?? 0);
            if ($issue <= 0) return;

            $qa = is_array($report['qa'] ?? null) ? $report['qa'] : [];
            $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
            $pr = is_array($report['pull_request'] ?? null) ? $report['pull_request'] : [];

            $this->repository->commentIssue(
                $issue,
                implode("\n", [
                    '## READY FOR HUMAN APPROVAL',
                    '',
                    'Feature: `'.$featureId.'`',
                    'PR: #'.((string) ($pr['number'] ?? '?')),
                    'Architecture: '.((string) ($report['architecture']['status'] ?? 'UNKNOWN')),
                    'Review: '.((string) ($report['review']['status'] ?? 'UNKNOWN')),
                    'QA: '.((string) ($qa['status'] ?? 'UNKNOWN')),
                    'Tests: '.((string) ($qa['tests_passed'] ?? 0)).'/'.((string) ($qa['tests_total'] ?? 0)),
                    'CI: '.((string) ($report['ci']['status'] ?? 'UNKNOWN')),
                    'Agent runs: '.((string) ($metrics['total_agent_runs'] ?? 0)),
                    'Cost: $'.number_format((float) ($metrics['total_cost'] ?? 0), 6, '.', ''),
                    'Time to READY: '.((string) ($metrics['time_to_ready_seconds'] ?? '?')).'s',
                    '',
                    'Human merge is required before DONE.',
                ]),
            );
        } catch (\Throwable) {
            // GitHub reporting must not invalidate an otherwise deterministic READY gate.
        }
    }

    /** @param list<array<string,mixed>> $changes */
    private function assertQaTestChanges(array $changes): void
    {
        if ($changes === [] || count($changes) > 20) {
            throw new RuntimeException('QA test mutation set must contain between 1 and 20 files.');
        }
        foreach ($changes as $change) {
            if (!is_array($change)) throw new RuntimeException('QA test mutation must be an object.');
            $path = str_replace('\\', '/', trim((string) ($change['path'] ?? '')));
            $operation = (string) ($change['operation'] ?? '');
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
                throw new RuntimeException('QA test mutation path is unsafe.');
            }
            if (!(str_starts_with($path, 'tests/') || str_starts_with($path, 'symfony/tests/'))) {
                throw new RuntimeException('QA is allowed to mutate only tests/ or symfony/tests/.');
            }
            if (!in_array($operation, ['CREATE','UPDATE'], true)) {
                throw new RuntimeException('QA may only create or update test code.');
            }
            if (!is_string($change['content'] ?? null)) {
                throw new RuntimeException('QA CREATE/UPDATE requires test file content.');
            }
        }
    }

    private function invariantVerified(array $qa, string $name): bool
    {
        $invariant = $qa['system_invariants'][$name] ?? null;
        if (!is_array($invariant)) return false;
        if (($invariant['applicable'] ?? null) === true) {
            return ($invariant['status'] ?? null) === 'PASS' && $this->hasMeaningfulEvidence($invariant['evidence'] ?? null);
        }
        return ($invariant['applicable'] ?? null) === false
            && ($invariant['status'] ?? null) === 'NOT_APPLICABLE'
            && trim((string) ($invariant['reason'] ?? '')) !== '';
    }

    private function hasMeaningfulEvidence(mixed $evidence): bool
    {
        if (is_string($evidence)) return trim($evidence) !== '';
        if (is_array($evidence)) return $evidence !== [];
        if (is_object($evidence)) return true;
        return is_int($evidence) || is_float($evidence) || is_bool($evidence);
    }

    private function requiredSuitesPassed(array $testPlan, array $qa): bool
    {
        $required = is_array($testPlan['required_suites'] ?? null) ? $testPlan['required_suites'] : [];
        $suites = is_array($qa['tests']['suites'] ?? null) ? $qa['tests']['suites'] : [];
        foreach ($required as $suite => $isRequired) {
            if ($isRequired !== true) continue;
            if (strtoupper((string) ($suites[$suite] ?? '')) !== 'PASS') return false;
        }
        return true;
    }

    private function smokePassed(array $testPlan, array $qa): bool
    {
        $required = (bool) ($testPlan['required_suites']['smoke'] ?? false);
        if (!$required) return true;
        return strtoupper((string) ($qa['tests']['suites']['smoke'] ?? '')) === 'PASS';
    }

    /** @return list<string> */
    private function requiredCiCheckNames(array $testPlan, array $implementation): array
    {
        $required = ['CI', 'Runtime'];
        if ((is_array($testPlan['ui_cases'] ?? null) ? $testPlan['ui_cases'] : []) !== []) $required[] = 'Frontend';
        if ((is_array($implementation['documentation_updates'] ?? null) ? $implementation['documentation_updates'] : []) !== []) $required[] = 'Documentation';
        return array_values(array_unique($required));
    }

    private function requiredCiChecksPassed(array $testPlan, array $implementation, array $ci): bool
    {
        if (($ci['state'] ?? null) !== 'SUCCESS') return false;

        $passed = [];
        foreach (is_array($ci['checks'] ?? null) ? $ci['checks'] : [] as $check) {
            if (!is_array($check)) continue;
            $status = strtolower((string) ($check['status'] ?? ''));
            $conclusion = strtolower((string) ($check['conclusion'] ?? ''));
            if ($status !== 'completed' || !in_array($conclusion, ['success','neutral','skipped'], true)) continue;
            $name = strtolower(trim((string) ($check['name'] ?? '')));
            if ($name !== '') $passed[$name] = true;
        }

        $aliases = [
            'CI' => ['ci','fast'],
            'Runtime' => ['runtime'],
            'Frontend' => ['frontend','build'],
            'Documentation' => ['documentation','validate and build docs'],
        ];

        foreach ($this->requiredCiCheckNames($testPlan, $implementation) as $required) {
            $matched = false;
            foreach ($aliases[$required] ?? [strtolower($required)] as $needle) {
                foreach (array_keys($passed) as $name) {
                    if ($name === strtolower($needle) || str_contains($name, strtolower($needle))) {
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) return false;
        }
        return true;
    }

    private function staticAnalysisPassed(array $ci): bool
    {
        if (($ci['state'] ?? null) !== 'SUCCESS') return false;
        foreach (is_array($ci['checks'] ?? null) ? $ci['checks'] : [] as $check) {
            if (!is_array($check)) continue;
            $name = strtolower(trim((string) ($check['name'] ?? '')));
            $conclusion = strtolower((string) ($check['conclusion'] ?? ''));
            if (
                (
                    str_contains($name, 'static')
                    || str_contains($name, 'phpstan')
                    || str_contains($name, 'psalm')
                    || $name === 'fast'
                    || $name === 'ci'
                )
                && in_array($conclusion, ['success','neutral','skipped'], true)
            ) return true;
        }
        return false;
    }

    private function documentationImpactChecked(array $implementation, array $development): bool
    {
        $required = [];
        foreach (is_array($implementation['documentation_updates'] ?? null) ? $implementation['documentation_updates'] : [] as $entry) {
            $path = is_string($entry)
                ? trim($entry)
                : (is_array($entry) ? trim((string) ($entry['path'] ?? '')) : '');
            if ($path !== '') $required[$path] = true;
        }
        if ($required === []) return true;

        $applied = [];
        foreach (array_merge(
            is_array($development['changed_files'] ?? null) ? $development['changed_files'] : [],
            is_array($development['architect_documentation_applied'] ?? null) ? $development['architect_documentation_applied'] : [],
        ) as $path) {
            if (is_string($path) && trim($path) !== '') $applied[trim($path)] = true;
        }
        foreach (array_keys($required) as $path) if (!isset($applied[$path])) return false;
        return true;
    }

    private function assertFeatureCoverage(array $featureSpec, array $qa): void
    {
        $expected = [];
        foreach (is_array($featureSpec['acceptance_criteria'] ?? null) ? $featureSpec['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) throw new RuntimeException('Feature Specification acceptance criteria are malformed.');
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id === '') throw new RuntimeException('Feature Specification acceptance criterion id is missing.');
            $expected[$id] = true;
        }

        $actual = [];
        foreach (is_array($qa['acceptance_criteria'] ?? null) ? $qa['acceptance_criteria'] : [] as $criterion) {
            if (!is_array($criterion)) continue;
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id !== '') $actual[$id] = true;
        }

        $missing = array_diff_key($expected, $actual);
        $unexpected = array_diff_key($actual, $expected);
        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException(sprintf(
                'QA acceptance-criteria coverage mismatch. Missing: %s; unexpected: %s.',
                implode(', ', array_keys($missing)) ?: 'none',
                implode(', ', array_keys($unexpected)) ?: 'none',
            ));
        }
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
