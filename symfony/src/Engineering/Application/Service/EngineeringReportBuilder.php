<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;

final readonly class EngineeringReportBuilder
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringFindingStoreInterface $findings,
    ) {}

    public function build(
        string $featureId,
        string $workflowId,
        string $status,
        string $recommendation,
        ?array $pullRequest = null,
        ?string $approvedBy = null,
        ?string $mergeRevision = null,
    ): array {
        $feature = $this->features->view($featureId);
        $architecture = $this->artifacts->latest($featureId, ArtifactType::ARCHITECTURE_DECISION);
        $development = $this->artifacts->latest($featureId, ArtifactType::DEVELOPMENT_RESULT);
        $review = $this->artifacts->latest($featureId, ArtifactType::REVIEW_REPORT);
        $qa = $this->artifacts->latest($featureId, ArtifactType::QA_REPORT);
        $runs = $this->agentRuns->forFeature($featureId);
        $workflow = $this->workflows->get($workflowId);

        $reviewCycles = 0;
        $qaPlanningRuns = 0;
        $qaCycles = 0;
        $developmentAttempts = 0;
        $architectureRuns = 0;
        $reviewRejections = 0;
        $qaRejections = 0;
        $technicalRetries = 0;
        $reviewDefects = 0;
        $qaDefects = 0;
        $regressions = 0;
        $totalTokens = 0;
        $totalCost = 0.0;
        $firstDeveloperFinishedAt = null;
        $readyAt = null;
        $developerProvider = null;
        $developerModel = null;
        $reviewerProvider = null;
        $reviewerModel = null;

        foreach ($runs as $run) {
            $role = $run['role'] ?? null;
            $output = is_array($run['output'] ?? null) ? $run['output'] : [];
            if ($role === AgentRole::PRINCIPAL_ARCHITECT->value) ++$architectureRuns;
            if ($role === AgentRole::DEVELOPER->value) {
                ++$developmentAttempts;
                $developerProvider = $run['provider'] ?? $developerProvider;
                $developerModel = $run['model'] ?? $developerModel;
                if ($firstDeveloperFinishedAt === null && is_string($run['finished_at'] ?? null)) $firstDeveloperFinishedAt = $run['finished_at'];
            }
            if ($role === AgentRole::REVIEWER->value) {
                ++$reviewCycles;
                $reviewerProvider = $run['provider'] ?? $reviewerProvider;
                $reviewerModel = $run['model'] ?? $reviewerModel;
                if (($output['status'] ?? null) === 'REQUEST_CHANGES') ++$reviewRejections;
                $reviewDefects += count(is_array($output['issues'] ?? null) ? $output['issues'] : []);
            }
            $legacyQaPlan = $role === AgentRole::QA->value && ($output['phase'] ?? null) === 'PLAN';
            $legacyQaExecution = $role === AgentRole::QA->value && ($output['phase'] ?? null) === 'EXECUTION';
            if ($role === AgentRole::QA_PLANNER->value || $legacyQaPlan) {
                ++$qaPlanningRuns;
            }
            if ($role === AgentRole::QA_EXECUTOR->value || $legacyQaExecution) {
                ++$qaCycles;
                if (($output['status'] ?? null) === 'FAIL') ++$qaRejections;
                if (($output['status'] ?? null) === 'PASS' && is_string($run['finished_at'] ?? null)) $readyAt = $run['finished_at'];
                $qaDefects += count(is_array($output['defects'] ?? null) ? $output['defects'] : []);
                $regressions += count(is_array($output['regressions'] ?? null) ? $output['regressions'] : []);
            }
            $technicalRetries += (int) ($run['technical_retry'] ?? 0);
            $totalTokens += (int) ($run['tokens_input'] ?? 0) + (int) ($run['tokens_output'] ?? 0);
            $totalCost += (float) ($run['cost'] ?? 0.0);
        }

        $qaContent = $qa['content'] ?? [];
        $developmentContent = $development['content'] ?? [];
        $reviewContent = $review['content'] ?? [];
        $architectureContent = $architecture['content'] ?? [];
        $ci = is_array($qaContent['ci_evidence'] ?? null) ? $qaContent['ci_evidence'] : [];
        $limitations = array_values(array_unique(array_merge(
            is_array($developmentContent['known_limitations'] ?? null) ? $developmentContent['known_limitations'] : [],
            is_array($qaContent['known_limitations'] ?? null) ? $qaContent['known_limitations'] : [],
        ), SORT_REGULAR));

        $humanInterventions = count($this->humanDecisions->historyForFeature($featureId));
        $qaExecutionCycles = $qaCycles;
        $startedAt = $workflow->startedAt();
        $timeToPr = $firstDeveloperFinishedAt !== null
            ? max(0, (new \DateTimeImmutable($firstDeveloperFinishedAt))->getTimestamp() - $startedAt->getTimestamp())
            : null;
        $timeToReady = $readyAt !== null
            ? max(0, (new \DateTimeImmutable($readyAt))->getTimestamp() - $startedAt->getTimestamp())
            : null;
        $success = in_array($status, ['READY_FOR_HUMAN_APPROVAL','DONE'], true);
        $accepted = $status === 'DONE';
        $firstPassSuccess = $success
            && $developmentAttempts === 1
            && $reviewCycles === 1
            && $qaExecutionCycles === 1
            && $humanInterventions === 0
            && $reviewRejections === 0
            && $qaRejections === 0;

        $reviewIndependence = 'UNKNOWN';
        if (is_string($developerProvider) && is_string($reviewerProvider)) {
            if ($developerProvider !== $reviewerProvider) $reviewIndependence = 'DIFFERENT_PROVIDER';
            elseif (is_string($developerModel) && is_string($reviewerModel) && $developerModel !== $reviewerModel) $reviewIndependence = 'DIFFERENT_MODEL';
            else $reviewIndependence = 'SAME_PROVIDER';
        }

        $openFindings = array_values(array_filter(
            $this->findings->forFeature($featureId),
            static fn (array $finding): bool => ($finding['status'] ?? null) === 'OPEN',
        ));

        return [
            'feature' => ['id' => $featureId, 'title' => $feature['title'] ?? null],
            'business_goal' => $feature['business_goal'] ?? null,
            'implementation_summary' => $developmentContent['implementation_summary'] ?? null,
            'pull_request' => $pullRequest ?? [
                'number' => $developmentContent['pull_request'] ?? null,
                'url' => $developmentContent['pull_request_url'] ?? null,
            ],
            'architecture' => [
                'status' => $architectureContent['gate_status'] ?? $architectureContent['status'] ?? null,
                'repository_revision' => $architectureContent['repository_revision'] ?? null,
                'primary_owner_domain' => $architectureContent['primary_owner_domain'] ?? null,
                'conditions' => is_array($architectureContent['conditions'] ?? null) ? $architectureContent['conditions'] : [],
                'decision' => $architectureContent['decision'] ?? null,
            ],
            'development' => ['status' => $developmentContent['status'] ?? null],
            'review' => [
                'status' => $reviewContent['status'] ?? null,
                'cycles' => $reviewCycles,
                'rejections' => $reviewRejections,
                'independence' => [
                    'developer_provider' => $developerProvider,
                    'developer_model' => $developerModel,
                    'reviewer_provider' => $reviewerProvider,
                    'reviewer_model' => $reviewerModel,
                    'classification' => $reviewIndependence,
                ],
            ],
            'qa' => [
                'status' => $qaContent['status'] ?? null,
                'planning_runs' => $qaPlanningRuns,
                'execution_cycles' => $qaExecutionCycles,
                'tests_total' => (int) ($qaContent['tests']['total'] ?? 0),
                'tests_passed' => (int) ($qaContent['tests']['passed'] ?? 0),
                'tests_failed' => (int) ($qaContent['tests']['failed'] ?? 0),
                'tests_skipped' => (int) ($qaContent['tests']['skipped'] ?? 0),
            ],
            'ci' => [
                'status' => $ci['state'] ?? null,
                'total' => (int) ($ci['total'] ?? 0),
                'passed' => (int) ($ci['passed'] ?? 0),
                'failed' => (int) ($ci['failed'] ?? 0),
                'required_checks' => is_array($qaContent['required_ci_checks'] ?? null) ? $qaContent['required_ci_checks'] : [],
                'checks' => is_array($ci['checks'] ?? null) ? $ci['checks'] : [],
            ],
            'risks' => $feature['risks'] ?? [],
            'known_limitations' => $limitations,
            'assumptions' => $feature['assumptions'] ?? [],
            'open_findings' => $openFindings,
            'tasks' => $this->tasks->forFeature($featureId),
            'agent_runs' => $runs,
            'metrics' => [
                'success' => $success,
                'accepted' => $accepted,
                'first_pass_success' => $firstPassSuccess,
                'human_interventions' => $humanInterventions,
                'development_attempts' => $developmentAttempts,
                'review_cycles' => $reviewCycles,
                'qa_cycles' => $qaExecutionCycles,
                'architecture_revalidations' => max(0, $architectureRuns - 1),
                'technical_retries' => $technicalRetries,
                'review_rejections' => $reviewRejections,
                'qa_rejections' => $qaRejections,
                'defects_found_review' => $reviewDefects,
                'defects_found_qa' => $qaDefects,
                'regressions' => $regressions,
                'total_agent_runs' => count($runs),
                'total_tokens' => $totalTokens,
                'total_cost' => round($totalCost, 6),
                'time_to_pr_seconds' => $timeToPr,
                'time_to_ready_seconds' => $timeToReady,
                'duration_seconds' => max(0, time() - $workflow->startedAt()->getTimestamp()),
            ],
            'total_tokens' => $totalTokens,
            'total_cost' => round($totalCost, 6),
            'duration_seconds' => max(0, time() - $workflow->startedAt()->getTimestamp()),
            'workflow_status' => $status,
            'recommendation' => $recommendation,
            'approved_by' => $approvedBy,
            'merge_revision' => $mergeRevision,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
