<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransition;
use App\Persistence\Doctrine\Entity\Engineering\WorkflowExecutionRecord;
use App\Persistence\Doctrine\Entity\Engineering\WorkflowTransitionRecord;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringWorkflowStore implements EngineeringWorkflowStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function create(WorkflowExecution $workflow): void
    {
        $record = new WorkflowExecutionRecord(
            id: $workflow->id(),
            featureId: $workflow->featureId(),
            workflowType: 'ENGINEERING',
            currentState: $workflow->currentState()->value,
            status: $this->statusFor($workflow->currentState()),
            traceId: $workflow->traceId(),
            lockKey: 'engineering:feature:'.$workflow->featureId().':workflow',
            version: 1,
            startedAt: $workflow->startedAt(),
            lastActivityAt: $workflow->lastActivityAt(),
            resumeState: $workflow->resumeState()?->value,
            finishedAt: $workflow->finishedAt(),
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function activeIdForFeature(string $featureId): ?string
    {
        $record = $this->entityManager->getRepository(WorkflowExecutionRecord::class)->findOneBy(
            ['featureId' => $featureId],
            ['startedAt' => 'DESC'],
        );
        if (!$record instanceof WorkflowExecutionRecord) return null;
        return in_array($record->status(), ['COMPLETED','CANCELLED','FAILED'], true) ? null : $record->id();
    }

    public function get(string $workflowId): WorkflowExecution
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }

        return WorkflowExecution::restore(
            id: $record->id(),
            featureId: $record->featureId(),
            currentState: EngineeringWorkflowState::from($record->currentState()),
            resumeState: $record->resumeState() !== null ? EngineeringWorkflowState::from($record->resumeState()) : null,
            traceId: $record->traceId(),
            version: $record->version(),
            startedAt: $record->startedAt(),
            lastActivityAt: $record->lastActivityAt(),
            finishedAt: $record->finishedAt(),
        );
    }

    public function saveTransition(WorkflowExecution $workflow, WorkflowTransition $transition): void
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflow->id());
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflow->id());
        }

        $record->syncState(
            $workflow->currentState()->value,
            $workflow->resumeState()?->value,
            $workflow->finishedAt(),
            $this->statusFor($workflow->currentState()),
        );

        $context = $transition->context;
        $this->entityManager->persist(new WorkflowTransitionRecord(
            id: $transition->id,
            workflowExecutionId: $transition->workflowExecutionId,
            featureId: $transition->featureId,
            fromState: $transition->from->value,
            toState: $transition->to->value,
            trigger: $context->trigger,
            reason: $context->reason,
            initiatedByType: $context->initiatedByType,
            initiatedById: $context->initiatedById,
            metadata: $context->metadata,
            createdAt: $transition->createdAt,
            agentRunId: $context->agentRunId,
            humanDecisionId: $context->humanDecisionId,
        ));
        $this->entityManager->flush();
    }

    private function statusFor(EngineeringWorkflowState $state): string
    {
        return match ($state) {
            EngineeringWorkflowState::DONE => 'COMPLETED',
            EngineeringWorkflowState::CANCELLED => 'CANCELLED',
            EngineeringWorkflowState::FAILED => 'FAILED',
            EngineeringWorkflowState::BLOCKED => 'BLOCKED',
            EngineeringWorkflowState::HUMAN_DECISION_REQUIRED,
            EngineeringWorkflowState::ESCALATED,
            EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL => 'WAITING',
            default => 'RUNNING',
        };
    }
}
