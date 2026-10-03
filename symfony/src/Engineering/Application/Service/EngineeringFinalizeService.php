<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use RuntimeException;

final readonly class EngineeringFinalizeService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {}

    public function finalize(string $featureId, string $actorId): array
    {
        if (!$this->repository->available()) {
            throw new RuntimeException('Engineering GitHub repository gateway is not configured.');
        }

        $workflowId = $this->workflows->activeIdForFeature($featureId);
        if ($workflowId === null) throw new RuntimeException('Engineering feature has no active workflow.');

        $development = $this->artifacts->latest($featureId, ArtifactType::DEVELOPMENT_RESULT);
        if ($development === null) throw new RuntimeException('Engineering feature has no DEVELOPMENT_RESULT.');
        $pullRequestNumber = (int) ($development['content']['pull_request'] ?? 0);
        if ($pullRequestNumber <= 0) throw new RuntimeException('Engineering feature has no pull request.');

        $pr = $this->repository->pullRequest($pullRequestNumber);
        if (!$pr['merged'] || trim((string) ($pr['merge_revision'] ?? '')) === '') {
            throw new RuntimeException('Pull request must be merged by a human before Engineering workflow can become DONE.');
        }

        return $this->lock->synchronized($featureId, function () use ($featureId, $workflowId, $actorId, $pr): array {
            $workflow = $this->workflows->get($workflowId);
            if ($workflow->currentState() !== EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
                throw new RuntimeException('Engineering workflow is not READY_FOR_HUMAN_APPROVAL.');
            }

            $next = $this->coordinator->completeAfterHumanApproval(
                $workflow,
                $actorId,
                (string) $pr['merge_revision'],
            );
            $this->persistTransitions($workflow, $next->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);

            $review = $this->artifacts->latest($featureId, ArtifactType::REVIEW_REPORT);
            $qa = $this->artifacts->latest($featureId, ArtifactType::QA_REPORT);
            $final = $this->artifacts->createVersion(
                $featureId,
                ArtifactType::FINAL_REPORT,
                [
                    'feature' => $this->features->view($featureId),
                    'workflow_id' => $workflowId,
                    'status' => 'DONE',
                    'pull_request' => $pr,
                    'development_result' => $development['content'],
                    'review_report' => $review['content'] ?? null,
                    'qa_report' => $qa['content'] ?? null,
                    'agent_runs' => $this->agentRuns->forFeature($featureId),
                    'tasks' => $this->tasks->forFeature($featureId),
                    'approved_by' => $actorId,
                    'merge_revision' => $pr['merge_revision'],
                    'completed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                createdByAgent: 'HUMAN',
            );

            return [
                'feature_id' => $featureId,
                'workflow_id' => $workflowId,
                'state' => $workflow->currentState()->value,
                'pull_request' => $pr,
                'final_report' => $final,
            ];
        });
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
