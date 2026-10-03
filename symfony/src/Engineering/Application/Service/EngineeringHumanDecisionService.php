<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\WorkflowExecution;

final readonly class EngineeringHumanDecisionService
{
    public function __construct(
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringManagerStageExecutor $managerStage,
        private EngineeringAutonomousProgressionService $progression,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
    ) {}

    public function answerAndResume(
        string $requestId,
        string $selectedOption,
        ?string $comment,
        string $decidedBy,
        string $organizationId,
        string $correlationId,
    ): EngineeringHumanDecisionResult {
        $request = $this->humanDecisions->get($requestId);
        $requestFeatureId = (string) ($request['feature_id'] ?? '');
        $feature = $this->features->view($requestFeatureId);
        if (($feature['organization_id'] ?? null) !== $organizationId) {
            throw new \RuntimeException('Engineering human decision does not belong to the current organization.');
        }
        if (($request['status'] ?? null) !== 'OPEN') {
            throw new \LogicException('Engineering human decision request is not open.');
        }

        $normalizedOption = strtoupper(trim($selectedOption));
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        $isCancel = $normalizedOption === 'CANCEL';
        if (!$this->offersOption($options, $normalizedOption)) {
            throw new \LogicException('Selected option is not offered for this human decision.');
        }

        $answer = $this->humanDecisions->answer($requestId, $selectedOption, $comment, $decidedBy);
        $featureId = $answer['feature_id'];
        $workflowId = $answer['workflow_id'];
        $decisionId = $answer['decision_id'];

        $next = $this->lock->synchronized($featureId, function () use ($workflowId, $decisionId, $featureId, $isCancel, $decidedBy) {
            $workflow = $this->workflows->get($workflowId);
            $directive = $isCancel
                ? $this->coordinator->cancel($workflow, $decidedBy, 'Human decision selected CANCEL.')
                : $this->coordinator->resumeAfterHumanDecision($workflow, $decisionId);
            $this->persistTransitions($workflow, $directive->transitions);
            $this->features->updateStatus($featureId, $workflow->currentState()->value);
            return $directive;
        });

        if ($next->agent === AgentRole::ENGINEERING_MANAGER) {
            $base = $this->features->request($featureId);
            $history = $base->previousContext;
            $history[] = [
                'human_decision' => [
                    'request_id' => $requestId,
                    'decision_id' => $decisionId,
                    'question' => $request['question'] ?? null,
                    'reason' => $request['reason'] ?? null,
                    'selected_option' => $selectedOption,
                    'comment' => $comment,
                    'decided_by' => $decidedBy,
                ],
            ];

            $managerRuns = array_values(array_filter(
                $this->agentRuns->forFeature($featureId),
                static fn (array $run): bool => ($run['role'] ?? null) === AgentRole::ENGINEERING_MANAGER->value,
            ));
            $logicalAttempt = count($managerRuns) + 1;

            $next = $this->managerStage->execute(
                featureId: $featureId,
                workflowId: $workflowId,
                request: new EngineeringRequest(
                    requestId: $base->requestId,
                    description: $base->description,
                    title: $base->title,
                    sourceType: $base->sourceType,
                    sourceReference: $base->sourceReference,
                    priority: $base->priority,
                    metadata: $base->metadata,
                    constraints: $base->constraints,
                    attachments: $base->attachments,
                    previousContext: $history,
                ),
                organizationId: $organizationId,
                correlationId: $correlationId,
                logicalAttempt: $logicalAttempt,
            );
        }

        $next = $this->progression->continue(
            featureId: $featureId,
            workflowId: $workflowId,
            directive: $next,
            organizationId: $organizationId,
            correlationId: $correlationId,
        );

        $workflow = $this->workflows->get($workflowId);
        return new EngineeringHumanDecisionResult(
            featureId: $featureId,
            workflowId: $workflowId,
            decisionId: $decisionId,
            state: $workflow->currentState()->value,
            next: $next,
        );
    }

    private function offersOption(array $options, string $selected): bool
    {
        foreach ($options as $option) {
            if (is_scalar($option) && strtoupper(trim((string) $option)) === $selected) return true;
            if (!is_array($option)) continue;

            foreach (['id', 'value', 'option'] as $key) {
                if (isset($option[$key]) && strtoupper(trim((string) $option[$key])) === $selected) return true;
            }
            if (is_array($option['options'] ?? null) && $this->offersOption($option['options'], $selected)) return true;
        }
        return false;
    }

    private function persistTransitions(WorkflowExecution $workflow, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflows->saveTransition($workflow, $transition);
        }
    }
}
