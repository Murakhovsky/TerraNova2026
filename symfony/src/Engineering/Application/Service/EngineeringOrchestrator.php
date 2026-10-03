<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Manager\EngineeringManagerAnalysisService;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Artifact\ArtifactType;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

final readonly class EngineeringOrchestrator
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringManagerAnalysisService $manager,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {
    }

    public function create(EngineeringRequest $request, ?string $createdBy = null): string
    {
        $featureId = EngineeringId::generate();
        $this->features->create($featureId, $request, $createdBy);
        return $featureId;
    }

    public function start(string $featureId, string $organizationId, string $correlationId): EngineeringStartResult
    {
        $workflow = new WorkflowExecution(
            EngineeringId::generate(),
            $featureId,
            EngineeringWorkflowState::NEW,
            $correlationId,
        );
        $this->workflows->create($workflow);

        $start = $this->coordinator->startAnalysis($workflow);
        $this->persistTransitions($workflow, $start->transitions);
        $this->features->updateStatus($featureId, $workflow->currentState()->value);

        $analysis = $this->manager->analyze(
            featureId: $featureId,
            request: $this->features->request($featureId),
            organizationId: $organizationId,
            correlationId: $correlationId,
        );

        $this->agentRuns->recordCompleted(
            workflowId: $workflow->id(),
            task: $analysis->task,
            result: $analysis->run,
            traceId: $correlationId,
        );

        $this->artifacts->createVersion(
            $featureId,
            ArtifactType::FEATURE_SPEC,
            $analysis->featureSpecification['feature'],
            agentRunId: $analysis->run->runId,
            createdByAgent: AgentRole::ENGINEERING_MANAGER->value,
        );
        $this->artifacts->createVersion(
            $featureId,
            ArtifactType::CONTEXT_MAP,
            $analysis->contextMap->toArray(),
            agentRunId: $analysis->run->runId,
            createdByAgent: AgentRole::ENGINEERING_MANAGER->value,
        );
        $this->tasks->createFromManager(
            $featureId,
            is_array($analysis->featureSpecification['tasks'] ?? null) ? $analysis->featureSpecification['tasks'] : [],
        );
        $this->features->applyManagerAnalysis(
            $featureId,
            $analysis->featureSpecification,
            $analysis->contextMap->toArray(),
            $analysis->contextMap->repositoryRevision,
        );

        $next = $this->coordinator->acceptAgentResult(
            $workflow,
            AgentRole::ENGINEERING_MANAGER,
            $analysis->run->structuredOutput,
        );
        $this->persistTransitions($workflow, $next->transitions);
        $this->features->updateStatus($featureId, $workflow->currentState()->value);

        return new EngineeringStartResult(
            $featureId,
            $workflow->id(),
            $workflow->currentState()->value,
            $next,
        );
    }

    /** @param list<\App\Engineering\Domain\Workflow\WorkflowTransition> $transitions */
    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
