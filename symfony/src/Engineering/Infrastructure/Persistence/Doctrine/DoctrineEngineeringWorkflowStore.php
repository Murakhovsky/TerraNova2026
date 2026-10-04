<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Workflow\EngineeringTransitionObserverInterface;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransition;
use App\Persistence\Doctrine\Entity\Engineering\WorkflowExecutionRecord;
use App\Persistence\Doctrine\Entity\Engineering\WorkflowTransitionRecord;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringWorkflowStore implements EngineeringWorkflowStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EngineeringTransitionObserverInterface $observer,
    ) {
    }

    public function create(WorkflowExecution $workflow, string $workflowType = 'ENGINEERING'): void
    {
        if (!in_array($workflowType, ['ENGINEERING','ENGINEERING_IMMEDIATE'], true)) {
            throw new \InvalidArgumentException('Unsupported Engineering workflow type.');
        }

        $record = new WorkflowExecutionRecord(
            id: $workflow->id(),
            featureId: $workflow->featureId(),
            workflowType: $workflowType,
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

    public function latestIdForFeature(string $featureId): ?string
    {
        $record = $this->entityManager->getRepository(WorkflowExecutionRecord::class)->findOneBy(
            ['featureId' => $featureId],
            ['startedAt' => 'DESC'],
        );
        return $record instanceof WorkflowExecutionRecord ? $record->id() : null;
    }

    public function markImmediate(string $workflowId): void
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }
        $record->markImmediate();
        $this->entityManager->flush();
    }

    public function resumable(int $limit = 20): array
    {
        return $this->orderedQueue(null, $limit);
    }

    public function queueForOrganization(string $organizationId, int $limit = 100): array
    {
        return $this->orderedQueue($organizationId, $limit);
    }

    /** @return list<array{feature_id:string,workflow_id:string,state:string,priority:string,started_at:string}> */
    private function orderedQueue(?string $organizationId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $qb
            ->select(
                'w.feature_id',
                'w.id AS workflow_id',
                'w.current_state AS state',
                'f.priority',
                'f.title',
                'f.status AS feature_status',
                'w.started_at',
            )
            ->from('cos_engineering_workflows', 'w')
            ->innerJoin('w', 'cos_engineering_features', 'f', 'f.id = w.feature_id')
            ->where("w.workflow_type = 'ENGINEERING'")
            ->andWhere("w.current_state IN ('ANALYSIS','QA_PLANNING','ARCHITECTURE_PENDING','DEVELOPMENT_RUNNING','REVIEW_PENDING','QA_PENDING')")
            ->orderBy("CASE f.priority WHEN 'P0' THEN 0 WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 WHEN 'P3' THEN 3 ELSE 9 END", 'ASC')
            ->addOrderBy('w.started_at', 'ASC')
            ->setMaxResults($limit);

        if ($organizationId !== null) {
            $qb->andWhere('f.organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'feature_id' => (string) ($row['feature_id'] ?? ''),
            'workflow_id' => (string) ($row['workflow_id'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'priority' => (string) ($row['priority'] ?? 'P2'),
            'title' => (string) ($row['title'] ?? ''),
            'feature_status' => (string) ($row['feature_status'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
        ], $rows);
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
        $this->observer->afterPersisted($transition);
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
