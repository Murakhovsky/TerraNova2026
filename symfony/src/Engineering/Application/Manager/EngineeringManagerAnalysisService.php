<?php
declare(strict_types=1);

namespace App\Engineering\Application\Manager;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Context\RepositoryDiscoveryInterface;
use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;

final readonly class EngineeringManagerAnalysisService
{
    public function __construct(
        private RepositoryDiscoveryInterface $repository,
        private EngineeringAgentRunnerInterface $agents,
        private EngineeringAgentOutputValidator $validator = new EngineeringAgentOutputValidator(),
    ) {
    }

    public function prepare(string $featureId, EngineeringRequest $request, int $logicalAttempt = 1): ManagerAnalysisPlan
    {
        EngineeringId::assert($featureId);
        $contextMap = $this->repository->discover($request);

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::ENGINEERING_MANAGER,
            objective: 'Analyze and formalize the engineering request into a Feature Specification and choose the next workflow action.',
            inputs: [
                'engineering_request' => [
                    'request_id' => $request->requestId,
                    'title' => $request->title,
                    'description' => $request->description,
                    'source' => ['type' => $request->sourceType, 'reference' => $request->sourceReference],
                    'priority' => $request->priority,
                    'metadata' => $request->metadata,
                    'constraints' => $request->constraints,
                    'attachments' => $request->attachments,
                    'previous_context' => $request->previousContext,
                ],
                'repository_context' => [
                    'trust' => 'UNTRUSTED_REPOSITORY_CONTENT',
                    'map' => $contextMap->toArray(),
                ],
            ],
            contextRefs: array_column($contextMap->files, 'path'),
            constraints: [
                'Do not implement production code.',
                'Do not make Principal Architect decisions.',
                'Do not silently invent business requirements.',
                'Use repository evidence before assumptions.',
                'Architecture stage is mandatory for V0.1 production tasks.',
            ],
            expectedOutputSchema: 'engineering-manager-result-v0.1',
            completionCriteria: [
                'Business goal is explicit.',
                'Scope and out-of-scope are explicit.',
                'Acceptance criteria are testable.',
                'Risks and assumptions are recorded.',
                'Tasks and dependencies are defined.',
                'Next action is explicit.',
            ],
            idempotencyKey: $featureId.':manager:'.$logicalAttempt.':'.$contextMap->repositoryRevision,
            inputSnapshot: [
                'feature_id' => $featureId,
                'repository_revision' => $contextMap->repositoryRevision,
                'request_id' => $request->requestId,
                'logical_attempt' => $logicalAttempt,
            ],
        );

        return new ManagerAnalysisPlan($contextMap, $task);
    }

    public function execute(ManagerAnalysisPlan $plan, string $organizationId, string $correlationId): ManagerAnalysisResult
    {
        $run = $this->agents->run($plan->task, $organizationId, $correlationId);
        if ($run->status !== 'completed') {
            throw new \RuntimeException('Engineering Manager Agent did not complete successfully: '.($run->error ?? $run->status));
        }

        $this->validator->validate(AgentRole::ENGINEERING_MANAGER, $run->structuredOutput);

        return new ManagerAnalysisResult(
            contextMap: $plan->contextMap,
            task: $plan->task,
            run: $run,
            featureSpecification: $run->structuredOutput,
        );
    }

    public function analyze(string $featureId, EngineeringRequest $request, string $organizationId, string $correlationId): ManagerAnalysisResult
    {
        return $this->execute($this->prepare($featureId, $request), $organizationId, $correlationId);
    }
}
