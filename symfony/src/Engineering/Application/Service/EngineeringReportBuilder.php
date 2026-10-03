<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
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
        $qaCycles = 0;
        $totalTokens = 0;
        $totalCost = 0.0;
        foreach ($runs as $run) {
            if (($run['role'] ?? null) === AgentRole::REVIEWER->value) ++$reviewCycles;
            if (($run['role'] ?? null) === AgentRole::QA->value) ++$qaCycles;
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
            ],
            'qa' => [
                'status' => $qaContent['status'] ?? null,
                'cycles' => $qaCycles,
                'tests_total' => (int) ($qaContent['tests_total'] ?? 0),
                'tests_passed' => (int) ($qaContent['tests_passed'] ?? 0),
                'tests_failed' => (int) ($qaContent['tests_failed'] ?? 0),
            ],
            'ci' => [
                'status' => $ci['state'] ?? null,
                'total' => (int) ($ci['total'] ?? 0),
                'passed' => (int) ($ci['passed'] ?? 0),
                'failed' => (int) ($ci['failed'] ?? 0),
            ],
            'risks' => $feature['risks'] ?? [],
            'known_limitations' => $limitations,
            'assumptions' => $feature['assumptions'] ?? [],
            'tasks' => $this->tasks->forFeature($featureId),
            'agent_runs' => $runs,
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
