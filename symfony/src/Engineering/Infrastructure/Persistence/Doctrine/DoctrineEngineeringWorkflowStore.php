<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Observability\EngineeringExecutionEventStoreInterface;
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
        private EngineeringExecutionEventStoreInterface $events,
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
            heartbeatAt: $workflow->lastActivityAt(),
            healthStatus: 'HEALTHY',
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

    public function view(string $workflowId): array
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }

        return [
            'id' => $record->id(),
            'feature_id' => $record->featureId(),
            'workflow_type' => $record->workflowType(),
            'state' => $record->currentState(),
            'status' => $record->status(),
            'trace_id' => $record->traceId(),
            'version' => $record->version(),
            'current_task_id' => $record->currentTaskId(),
            'current_agent_run_id' => $record->currentAgentRunId(),
            'resume_state' => $record->resumeState(),
            'started_at' => $record->startedAt()->format(DATE_ATOM),
            'last_activity_at' => $record->lastActivityAt()->format(DATE_ATOM),
            'heartbeat_at' => $record->heartbeatAt()?->format(DATE_ATOM),
            'health_status' => $record->healthStatus(),
            'stalled_at' => $record->stalledAt()?->format(DATE_ATOM),
            'runtime_reason' => $record->runtimeReason(),
            'finished_at' => $record->finishedAt()?->format(DATE_ATOM),
        ];
    }

    public function markImmediate(string $workflowId): void
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }
        $record->markImmediate();
        $record->touchRuntime();
        $this->entityManager->flush();
    }

    public function touchRuntime(string $workflowId, ?string $agentRunId = null, ?string $taskId = null): void
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }
        if (in_array($record->status(), ['COMPLETED','CANCELLED','FAILED'], true)) return;
        $previousHealth = $record->healthStatus();
        $previousReason = $record->runtimeReason();
        $record->touchRuntime($agentRunId, $taskId);
        $this->entityManager->flush();
        if (in_array($previousHealth, ['STALE','STALLED'], true)) {
            $this->events->append(
                $record->featureId(),
                $record->id(),
                'WATCHDOG',
                'workflow.heartbeat_recovered',
                'HEALTHY',
                'Workflow runtime heartbeat recovered',
                [
                    'previous_health' => $previousHealth,
                    'previous_reason' => $previousReason,
                    'current_task_id' => $taskId,
                    'current_agent_run_id' => $agentRunId,
                ],
                $agentRunId,
                $record->traceId(),
            );
        }
    }

    public function markRuntimeIssue(string $workflowId, string $health, string $reason, ?string $agentRunId = null, ?string $taskId = null): void
    {
        $record = $this->entityManager->find(WorkflowExecutionRecord::class, $workflowId);
        if (!$record instanceof WorkflowExecutionRecord) {
            throw new RuntimeException('Engineering workflow not found: '.$workflowId);
        }
        if (in_array($record->status(), ['COMPLETED','CANCELLED','FAILED'], true)) return;

        $previousHealth = $record->healthStatus();
        $record->markRuntimeIssue($health, $reason, $agentRunId, $taskId);
        $this->entityManager->flush();
        $this->events->append(
            $record->featureId(),
            $record->id(),
            'RUNTIME',
            'workflow.runtime_issue',
            $record->healthStatus(),
            'Workflow runtime issue detected',
            [
                'previous_health' => $previousHealth,
                'health' => $record->healthStatus(),
                'reason' => $record->runtimeReason(),
                'current_task_id' => $taskId,
                'current_agent_run_id' => $agentRunId,
            ],
            $agentRunId,
            $record->traceId(),
            error: $reason,
        );
    }

    public function refreshRuntimeHealthForOrganization(string $organizationId, int $staleAfterSeconds = 600, int $stalledAfterSeconds = 1800): array
    {
        $staleAfterSeconds = max(60, $staleAfterSeconds);
        $stalledAfterSeconds = max($staleAfterSeconds + 60, $stalledAfterSeconds);
        $db = $this->entityManager->getConnection();

        $rows = $db->fetchAllAssociative(
            "SELECT w.id, w.feature_id, w.trace_id, w.current_state, w.status, w.health_status,
                    TIMESTAMPDIFF(SECOND, COALESCE(w.heartbeat_at, w.last_activity_at), UTC_TIMESTAMP(6)) AS age_seconds
             FROM cos_engineering_workflows w
             INNER JOIN cos_engineering_features f ON f.id = w.feature_id
             WHERE f.organization_id = :organization_id
               AND w.status NOT IN ('COMPLETED','CANCELLED','FAILED')",
            ['organization_id' => $organizationId],
        );

        $counts = ['healthy' => 0, 'stale' => 0, 'stalled' => 0, 'waiting' => 0];
        foreach ($rows as $row) {
            $state = (string) ($row['current_state'] ?? '');
            $featureStatus = strtoupper((string) ($row['feature_status'] ?? ''));
            $age = max(0, (int) ($row['age_seconds'] ?? 0));
            if ($featureStatus === 'QUEUED') {
                $health = 'WAITING';
                ++$counts['waiting'];
                $reason = 'Workflow is waiting in the priority queue.';
            } elseif (in_array($state, ['HUMAN_DECISION_REQUIRED','READY_FOR_HUMAN_APPROVAL','BLOCKED','ESCALATED'], true)) {
                $health = 'WAITING';
                ++$counts['waiting'];
                $reason = 'Workflow is waiting for explicit human action.';
            } elseif ($age >= $stalledAfterSeconds) {
                $health = 'STALLED';
                ++$counts['stalled'];
                $reason = sprintf('No runtime heartbeat for %d seconds.', $age);
            } elseif ($age >= $staleAfterSeconds) {
                $health = 'STALE';
                ++$counts['stale'];
                $reason = sprintf('Runtime heartbeat is delayed by %d seconds.', $age);
            } else {
                $health = 'HEALTHY';
                ++$counts['healthy'];
                $reason = null;
            }

            $previousHealth = strtoupper((string) ($row['previous_health'] ?? 'UNKNOWN'));
            $db->executeStatement(
                "UPDATE cos_engineering_workflows
                 SET health_status = :health,
                     stalled_at = CASE WHEN :health = 'STALLED' THEN COALESCE(stalled_at, UTC_TIMESTAMP(6)) ELSE NULL END,
                     runtime_reason = :reason
                 WHERE id = :id",
                ['health' => $health, 'reason' => $reason, 'id' => (string) $row['id']],
            );
            if ($previousHealth !== $health) {
                $this->events->append(
                    (string) $row['feature_id'],
                    (string) $row['id'],
                    'WATCHDOG',
                    'workflow.health_changed',
                    $health,
                    'Workflow runtime health changed from '.$previousHealth.' to '.$health,
                    [
                        'previous_health' => $previousHealth,
                        'health' => $health,
                        'age_seconds' => $age,
                        'reason' => $reason,
                    ],
                    correlationId: (string) ($row['trace_id'] ?? ''),
                );
            }
            $previousHealth = strtoupper((string) ($row['health_status'] ?? 'UNKNOWN'));
            if ($previousHealth !== $health) {
                $this->events->append(
                    (string) $row['feature_id'],
                    (string) $row['id'],
                    'WATCHDOG',
                    'runtime.health_changed',
                    $health,
                    sprintf('Runtime health changed from %s to %s', $previousHealth, $health),
                    [
                        'previous_health' => $previousHealth,
                        'health' => $health,
                        'age_seconds' => $age,
                        'stale_after_seconds' => $staleAfterSeconds,
                        'stalled_after_seconds' => $stalledAfterSeconds,
                        'reason' => $reason,
                    ],
                    correlationId: (string) ($row['trace_id'] ?? ''),
                    error: $health === 'STALLED' ? $reason : null,
                );
            }
        }

        $this->entityManager->clear(WorkflowExecutionRecord::class);
        return $counts;
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

    /** @return list<array{feature_id:string,workflow_id:string,workflow_type:string,workflow_status:string,state:string,priority:string,title:string,feature_status:string,started_at:string,last_activity_at:string}> */
    public function activeForOrganization(string $organizationId, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select(
                'w.feature_id',
                'w.id AS workflow_id',
                'w.workflow_type',
                'w.status AS workflow_status',
                'w.current_state AS state',
                'f.priority',
                'f.title',
                'f.status AS feature_status',
                'w.started_at',
                'w.last_activity_at',
                'w.heartbeat_at',
                'w.health_status',
                'w.stalled_at',
                'w.runtime_reason',
            )
            ->from('cos_engineering_workflows', 'w')
            ->innerJoin('w', 'cos_engineering_features', 'f', 'f.id = w.feature_id')
            ->where('f.organization_id = :organization_id')
            ->andWhere("w.status NOT IN ('COMPLETED','CANCELLED','FAILED')")
            ->setParameter('organization_id', $organizationId)
            ->orderBy('w.last_activity_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'feature_id' => (string) ($row['feature_id'] ?? ''),
            'workflow_id' => (string) ($row['workflow_id'] ?? ''),
            'workflow_type' => (string) ($row['workflow_type'] ?? 'ENGINEERING'),
            'workflow_status' => (string) ($row['workflow_status'] ?? 'RUNNING'),
            'state' => (string) ($row['state'] ?? ''),
            'priority' => (string) ($row['priority'] ?? 'P2'),
            'title' => (string) ($row['title'] ?? ''),
            'feature_status' => (string) ($row['feature_status'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'last_activity_at' => (string) ($row['last_activity_at'] ?? ''),
            'heartbeat_at' => (string) ($row['heartbeat_at'] ?? ''),
            'health_status' => (string) ($row['health_status'] ?? 'UNKNOWN'),
            'stalled_at' => (string) ($row['stalled_at'] ?? ''),
            'runtime_reason' => $row['runtime_reason'] !== null ? (string) $row['runtime_reason'] : null,
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
            $transition->context->reason,
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
