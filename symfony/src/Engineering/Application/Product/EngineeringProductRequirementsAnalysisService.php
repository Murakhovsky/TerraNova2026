<?php
declare(strict_types=1);

namespace App\Engineering\Application\Product;

use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Application\Context\EngineeringStandardsProvider;
use App\Engineering\Application\Context\RepositoryDiscoveryInterface;
use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;

final readonly class EngineeringProductRequirementsAnalysisService
{
    public function __construct(
        private RepositoryDiscoveryInterface $repository,
        private EngineeringStandardsProvider $standards,
        private EngineeringAgentRunnerInterface $agents,
        private EngineeringAgentOutputValidator $validator = new EngineeringAgentOutputValidator(),
    ) {
    }

    public function prepare(string $featureId, EngineeringRequest $request, int $logicalAttempt = 1): ProductRequirementsAnalysisPlan
    {
        EngineeringId::assert($featureId);
        $contextMap = $this->repository->discover($request);

        $task = new EngineeringAgentTask(
            id: EngineeringId::generate(),
            featureId: $featureId,
            role: AgentRole::PRODUCT_REQUIREMENTS,
            objective: 'Formalize the engineering request into an explicit Feature Specification, acceptance criteria, business rules, scope and open product questions.',
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
                'engineering_standards' => $this->standards->all(),
            ],
            contextRefs: array_values(array_unique(array_merge(
                array_column($contextMap->files, 'path'),
                $this->standards->paths(),
            ))),
            constraints: [
                'Do not implement production code.',
                'Do not make Principal Architect decisions.',
                'Do not orchestrate workflow transitions; return product requirements only.',
                'Do not silently invent business requirements.',
                'If status is HUMAN_DECISION_REQUIRED, return exactly one top-level blocking open question with explicit options; keep other non-blocking ambiguities under feature.open_questions.',
                'Use repository evidence before assumptions.',
                'Architecture stage is mandatory for V0.1 production tasks.',
            ],
            expectedOutputSchema: 'product-requirements-result-v2.0',
            completionCriteria: [
                'Business goal is explicit.',
                'Scope and out-of-scope are explicit.',
                'Acceptance criteria are testable.',
                'Risks and assumptions are recorded.',
                'Tasks and dependencies are defined.',
                'Blocking ambiguity, when present, is represented by exactly one answerable top-level human-decision question.',
            ],
            idempotencyKey: $featureId.':product-requirements:'.$logicalAttempt.':'.$contextMap->repositoryRevision,
            inputSnapshot: [
                'feature_id' => $featureId,
                'repository_revision' => $contextMap->repositoryRevision,
                'request_id' => $request->requestId,
                'logical_attempt' => $logicalAttempt,
            ],
        );

        return new ProductRequirementsAnalysisPlan($contextMap, $task);
    }

    public function execute(ProductRequirementsAnalysisPlan $plan, string $organizationId, string $correlationId): ProductRequirementsAnalysisResult
    {
        $run = $this->agents->run($plan->task, $organizationId, $correlationId);
        if ($run->status !== 'completed') {
            throw new \RuntimeException('Product / Requirements Agent did not complete successfully: '.($run->error ?? $run->status));
        }

        $this->validator->validate(AgentRole::PRODUCT_REQUIREMENTS, $run->structuredOutput);

        return new ProductRequirementsAnalysisResult(
            contextMap: $plan->contextMap,
            task: $plan->task,
            run: $run,
            featureSpecification: $run->structuredOutput,
        );
    }

    public function analyze(string $featureId, EngineeringRequest $request, string $organizationId, string $correlationId): ProductRequirementsAnalysisResult
    {
        return $this->execute($this->prepare($featureId, $request), $organizationId, $correlationId);
    }
}
