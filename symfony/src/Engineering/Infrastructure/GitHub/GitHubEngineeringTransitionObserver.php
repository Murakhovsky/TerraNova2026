<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\GitHub;

use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringTransitionObserverInterface;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowTransition;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class GitHubEngineeringTransitionObserver implements EngineeringTransitionObserverInterface
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringRepositoryGatewayInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function afterPersisted(WorkflowTransition $transition): void
    {
        $message = $this->message($transition->to);
        if ($message === null || !$this->repository->available()) return;

        try {
            $feature = $this->features->view($transition->featureId);
            $issue = (int) ($feature['external_issue_id'] ?? 0);
            if ($issue <= 0) return;

            $this->repository->commentIssue(
                $issue,
                sprintf(
                    "COS Engineering feature %s -> **%s**\n\n%s\n\nWorkflow: %s\nTrigger: %s",
                    $transition->featureId,
                    $transition->to->value,
                    $message,
                    $transition->workflowExecutionId,
                    $transition->context->trigger,
                ),
            );
        } catch (Throwable $error) {
            $this->logger->warning('Engineering GitHub issue synchronization failed.', [
                'feature_id' => $transition->featureId,
                'workflow_id' => $transition->workflowExecutionId,
                'state' => $transition->to->value,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function message(EngineeringWorkflowState $state): ?string
    {
        return match ($state) {
            EngineeringWorkflowState::SPECIFICATION_READY => 'Feature Specification is ready.',
            EngineeringWorkflowState::ARCHITECTURE_APPROVED => 'Architecture has been approved.',
            EngineeringWorkflowState::REVIEW_PENDING => 'Development completed; review is pending.',
            EngineeringWorkflowState::CHANGES_REQUESTED => 'Reviewer requested implementation changes.',
            EngineeringWorkflowState::QA_PENDING => 'Review approved; QA is pending.',
            EngineeringWorkflowState::QA_FAILED => 'QA failed; implementation returns to Developer.',
            EngineeringWorkflowState::HUMAN_DECISION_REQUIRED => 'Workflow requires a human decision.',
            EngineeringWorkflowState::BLOCKED => 'Workflow is blocked.',
            EngineeringWorkflowState::ESCALATED => 'Workflow has been escalated.',
            EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL => 'All automated gates passed. Ready for human approval.',
            EngineeringWorkflowState::DONE => 'Human approval/merge confirmed. Workflow is done.',
            EngineeringWorkflowState::CANCELLED => 'Workflow was cancelled.',
            EngineeringWorkflowState::FAILED => 'Workflow failed.',
            default => null,
        };
    }
}
