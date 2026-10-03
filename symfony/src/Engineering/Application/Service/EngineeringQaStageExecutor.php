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

        $featureSpec = $this->requiredArtifact($featureId, ArtifactType::FEATURE_SPEC);
        $architecture = $this->requiredArtifact($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $development = $this->requiredArtifact($featureId, ArtifactType::DEVELOPMENT_RESULT);
        $review = $this->requiredArtifact($featureId, ArtifactType::REVIEW_REPORT);

        $revision = trim((string) ($development['content']['repository_revision'] ?? ''));
        if ($revision === '') throw new RuntimeException('QA requires a concrete repository revision.');

        $ci = $this->repository->commitChecks($revision);
        if ($ci['state'] === 'PENDING') {
            return new WorkflowDirective(
                WorkflowDirectiveType::STOP,
                null,
                'QA is waiting for GitHub CI to finish for revision '.$revision.'.',
            );
        }

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::QA,
            objective: 'Validate acceptance criteria and regression risk for the reviewed revision using GitHub CI evidence and review artifacts.',
            inputs: [
                'feature_spec' => $featureSpec['content'],
                'architecture_decision' => $architecture['content'],
                'development_result' => $development['content'],
                'review_report' => $review['content'],
                'ci_evidence' => $ci,
            ],
            contextRefs: [
                'artifact:'.$featureSpec['id'],
                'artifact:'.$architecture['id'],
                'artifact:'.$development['id'],
                'artifact:'.$review['id'],
                'commit:'.$revision,
            ],
            constraints: [
                'Do not modify repository content.',
                'Do not report PASS when deterministic CI evidence failed.',
                'Evaluate every Feature Specification acceptance criterion.',
                'Report regressions and known limitations explicitly.',
            ],
            expectedOutputSchema: 'qa-result-v0.1',
            completionCriteria: [
                'Test plan is explicit.',
                'Every acceptance criterion has PASS/FAIL/BLOCKED and evidence.',
                'Test totals are internally consistent.',
                'Result is tied to the reviewed repository revision.',
            ],
            idempotencyKey: $featureId.':qa:'.$logicalAttempt.':'.$revision,
            inputSnapshot: [
                'feature_id' => $featureId,
                'repository_revision' => $revision,
                'feature_spec_hash' => $featureSpec['content_hash'],
                'review_report_hash' => $review['content_hash'],
                'ci_state' => $ci['state'],
                'logical_attempt' => $logicalAttempt,
            ],
        );

        $engineeringRunId = $this->lock->synchronized($featureId, function () use ($workflowId, $task, $correlationId): string {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
                throw new WorkflowAlreadyRunningException('QA stage can run only from QA_PENDING.');
            }
            return $this->agentRuns->start($workflowId, $task, $correlationId);
        });

        try {
            $run = $this->agents->run($task, $organizationId, $correlationId);
            if ($run->status !== 'completed') {
                throw new RuntimeException('QA Agent did not complete: '.($run->error ?? $run->status));
            }
            $this->validator->validate(AgentRole::QA, $run->structuredOutput);
            if (($run->structuredOutput['tested_revision'] ?? null) !== $revision) {
                throw new RuntimeException('QA output revision does not match reviewed revision.');
            }

            $effective = $run->structuredOutput;
            $effective['ci_evidence'] = $ci;
            if ($ci['state'] === 'FAILED') {
                $effective['status'] = 'FAIL';
                $effective['tests_total'] = max((int) ($effective['tests_total'] ?? 0), $ci['total']);
                $effective['tests_failed'] = max((int) ($effective['tests_failed'] ?? 0), $ci['failed']);
                $effective['tests_passed'] = min(
                    (int) ($effective['tests_passed'] ?? 0),
                    max(0, $effective['tests_total'] - $effective['tests_failed']),
                );
                $effective['defects'][] = [
                    'category' => 'QUALITY',
                    'severity' => 'HIGH',
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
            );
        } catch (\Throwable $error) {
            $this->lock->synchronized(
                $featureId,
                fn () => $this->agentRuns->fail($engineeringRunId, 'TASK_ERROR', $error->getMessage()),
            );
            throw $error;
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $run, $engineeringRunId, $ci, $featureSpec, $architecture, $development, $review): WorkflowDirective {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
                throw new WorkflowAlreadyRunningException('Engineering workflow changed while QA was running.');
            }

            $this->agentRuns->complete($engineeringRunId, $run);
            if (($run->structuredOutput['status'] ?? null) === 'PASS') {
                $this->findings->resolveOpenForSource($featureId, AgentRole::QA, $engineeringRunId);
            }
            $this->findings->recordFindings(
                featureId: $featureId,
                sourceRole: AgentRole::QA,
                findings: is_array($run->structuredOutput['defects'] ?? null) ? $run->structuredOutput['defects'] : [],
                agentRunId: $engineeringRunId,
            );
            $this->artifacts->createVersion(
                $featureId,
                ArtifactType::TEST_PLAN,
                ['tests' => $run->structuredOutput['test_plan'] ?? []],
                agentRunId: $engineeringRunId,
                createdByAgent: AgentRole::QA->value,
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
                developmentCompleted: ($development['content']['status'] ?? null) === 'COMPLETED',
                reviewApproved: ($review['content']['status'] ?? null) === 'APPROVED',
                qaPassed: ($run->structuredOutput['status'] ?? null) === 'PASS',
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
            $results[(string) ($criterion['id'] ?? '')] = (string) ($criterion['result'] ?? '');
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
        return new WorkflowCounters(max(0, $developer - 1), $reviewer, $qa);
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
