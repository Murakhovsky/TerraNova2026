<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;

final readonly class EngineeringStatusService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringFindingStoreInterface $findings,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
    ) {}

    public function status(string $featureId): array
    {
        $feature = $this->features->view($featureId);
        $workflowId = $this->workflows->latestIdForFeature($featureId);
        $workflow = null;
        if ($workflowId !== null) {
            $current = $this->workflows->get($workflowId);
            $workflow = [
                'id' => $current->id(),
                'state' => $current->currentState()->value,
                'resume_state' => $current->resumeState()?->value,
                'trace_id' => $current->traceId(),
                'version' => $current->version(),
                'started_at' => $current->startedAt()->format(DATE_ATOM),
                'last_activity_at' => $current->lastActivityAt()->format(DATE_ATOM),
                'finished_at' => $current->finishedAt()?->format(DATE_ATOM),
            ];
        }

        $artifactViews = [];
        foreach (ArtifactType::cases() as $type) {
            $artifact = $this->artifacts->latest($featureId, $type);
            if ($artifact === null) continue;
            $artifactViews[$type->value] = $artifact;
        }

        return [
            'feature' => $feature,
            'workflow' => $workflow,
            'tasks' => $this->tasks->forFeature($featureId),
            'agent_runs' => $this->agentRuns->forFeature($featureId),
            'artifacts' => $artifactViews,
            'findings' => $this->findings->forFeature($featureId),
            'open_human_decisions' => $this->humanDecisions->openForFeature($featureId),
        ];
    }
}
