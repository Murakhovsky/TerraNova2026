<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Observability\EngineeringExecutionEventStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\AgentRunRecord;
use App\Persistence\Doctrine\Entity\Engineering\WorkflowExecutionRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringAgentRunStore implements EngineeringAgentRunStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringExecutionEventStoreInterface $events,
    ) {}

    public function start(string $workflowId, EngineeringAgentTask $task, string $traceId): string
    {
        $existing = $this->recordByIdempotencyKey($task->idempotencyKey);
        if ($existing instanceof AgentRunRecord) {
            throw new RuntimeException('Engineering AgentRun idempotency key already exists: '.$task->idempotencyKey);
        }

        $running = $this->entityManager->getRepository(AgentRunRecord::class)->findOneBy([
            'featureId' => $task->featureId,
            'agentRole' => $task->role->value,
            'status' => 'RUNNING',
        ]);
        if ($running instanceof AgentRunRecord) {
            throw new RuntimeException('Engineering AgentRun is already RUNNING for role '.$task->role->value.'.');
        }

        $runId = EngineeringId::generate();
        $runTraceId = $this->runCorrelationId($traceId, $task->id);

        $this->entityManager->getConnection()->transactional(function () use ($workflowId, $task, $runId, $runTraceId): void {
            $this->entityManager->persist(new AgentRunRecord(
                id: $runId,
                featureId: $task->featureId,
                workflowExecutionId: $workflowId,
                agentId: strtolower($task->role->value).':'.$task->id,
                agentRole: $task->role->value,
                idempotencyKey: $task->idempotencyKey,
                modelProvider: 'pending',
                model: 'pending',
                inputSnapshot: array_merge($task->inputSnapshot, [
                    '_execution_task_id' => $task->id,
                ]),
                status: 'RUNNING',
                technicalRetry: 0,
                logicalAttempt: max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1)),
                traceId: $runTraceId,
                startedAt: new DateTimeImmutable(),
                taskId: null,
            ));
            $this->entityManager->flush();

            $this->events->append(
                $task->featureId,
                $workflowId,
                'AGENT',
                'agent.run_started',
                'STARTED',
                $task->role->value.' agent run started',
                [
                    'role' => $task->role->value,
                    'agent_role' => $task->role->value,
                    'execution_task_id' => $task->id,
                    'persisted_task_id' => null,
                    'logical_attempt' => max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1)),
                    'technical_retry' => 0,
                    'revision' => $this->revisionFromSnapshot($task->inputSnapshot),
                    'provider' => 'pending',
                    'model' => 'pending',
                    'cost' => null,
                    'result' => 'STARTED',
                    'objective' => mb_substr($task->objective, 0, 500),
                ],
                $runId,
                $runTraceId,
            );

            // EngineeringAgentTask::id is an execution correlation id, not a persisted
            // cos_engineering_tasks.id. Persisting it into task_id/current_task_id violates
            // their foreign keys for stage-level agents (Product, QA Planner, Architect, ...).
            $this->workflows->touchRuntime($workflowId, $runId, null);
        });

        return $runId;
    }

    public function complete(string $engineeringRunId, EngineeringAgentRunResult $result): void
    {
        $record = $this->entityManager->find(AgentRunRecord::class, $engineeringRunId);
        if (!$record instanceof AgentRunRecord) throw new RuntimeException('Engineering AgentRun not found: '.$engineeringRunId);

        $usage = $result->usage;
        $record->complete(
            status: strtoupper($result->status),
            output: array_merge($result->structuredOutput, [
                '_kernel_run_id' => $result->runId,
                '_runtime_steps' => $result->steps,
            ]),
            provider: $result->provider ?? 'unknown',
            model: $result->model ?? 'unknown',
            tokensInput: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            tokensOutput: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            estimatedCost: isset($usage['cost_amount']) ? (string) $usage['cost_amount'] : null,
            errorType: $result->error !== null ? 'TASK_ERROR' : null,
            errorMessage: $result->error,
            technicalRetry: $result->technicalRetries,
        );
        $this->entityManager->flush();
        $this->events->append(
            $record->featureId(),
            $record->workflowExecutionId(),
            'AGENT',
            'agent.run_completed',
            strtoupper($result->status),
            $record->agentRole().' agent run completed',
            [
                'role' => $record->agentRole(),
                'agent_role' => $record->agentRole(),
                'logical_attempt' => $record->logicalAttempt(),
                'technical_retry' => $result->technicalRetries,
                'revision' => $this->revisionFromSnapshot($record->inputSnapshot()),
                'provider' => $result->provider,
                'model' => $result->model,
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'cost' => $usage['cost_amount'] ?? null,
                'cost_amount' => $usage['cost_amount'] ?? null,
                'result' => strtoupper($result->status),
                'runtime_steps' => count($result->steps),
            ],
            $record->id(),
            $record->traceId(),
            $this->durationMs($record),
            $result->error,
        );
        $this->workflows->touchRuntime($record->workflowExecutionId(), $record->id(), $record->taskId());
    }

    public function fail(string $engineeringRunId, string $errorType, string $errorMessage, int $technicalRetry = 0): void
    {
        $record = $this->entityManager->find(AgentRunRecord::class, $engineeringRunId);
        if (!$record instanceof AgentRunRecord) throw new RuntimeException('Engineering AgentRun not found: '.$engineeringRunId);
        $record->fail($errorType, $errorMessage, $technicalRetry);
        $this->entityManager->flush();
        $this->events->append(
            $record->featureId(),
            $record->workflowExecutionId(),
            'AGENT',
            'agent.run_failed',
            'FAILED',
            $record->agentRole().' agent run failed',
            [
                'role' => $record->agentRole(),
                'agent_role' => $record->agentRole(),
                'logical_attempt' => $record->logicalAttempt(),
                'technical_retry' => $technicalRetry,
                'revision' => $this->revisionFromSnapshot($record->inputSnapshot()),
                'provider' => $record->modelProvider(),
                'model' => $record->model(),
                'cost' => $record->estimatedCost(),
                'result' => 'FAILED',
                'error_type' => $errorType,
            ],
            $record->id(),
            $record->traceId(),
            $this->durationMs($record),
            $errorMessage,
        );
        $this->workflows->markRuntimeIssue(
            $record->workflowExecutionId(),
            'STALLED',
            'AgentRun '.$record->agentRole().' failed ['.$errorType.']: '.mb_substr($errorMessage, 0, 500),
            $record->id(),
            $record->taskId(),
        );
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        return $this->recordByIdempotencyKey($idempotencyKey) instanceof AgentRunRecord;
    }

    public function failStaleRunning(string $featureId, \App\Engineering\Domain\Agent\AgentRole $role, int $staleAfterSeconds): int
    {
        $staleAfterSeconds = max(60, $staleAfterSeconds);
        $now = new DateTimeImmutable();
        $threshold = $now->modify('-'.$staleAfterSeconds.' seconds');
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy([
            'featureId' => $featureId,
            'agentRole' => $role->value,
            'status' => 'RUNNING',
        ]);

        $failed = 0;
        foreach ($records as $record) {
            if (!$record instanceof AgentRunRecord) continue;

            $workflow = $this->entityManager->find(WorkflowExecutionRecord::class, $record->workflowExecutionId());
            $heartbeatAt = $workflow instanceof WorkflowExecutionRecord
                && $workflow->currentAgentRunId() === $record->id()
                ? $workflow->heartbeatAt()
                : null;

            // A long-running agent is not stale while its own workflow heartbeat is fresh.
            // Recovery must be based on loss of liveness, never merely on run age.
            if ($heartbeatAt instanceof DateTimeImmutable && $heartbeatAt > $threshold) {
                continue;
            }

            if (!$heartbeatAt instanceof DateTimeImmutable && $record->startedAt() > $threshold) {
                continue;
            }

            $effectiveFinishedAt = $heartbeatAt instanceof DateTimeImmutable
                ? $heartbeatAt
                : $record->startedAt()->modify('+'.$staleAfterSeconds.' seconds');

            $message = $heartbeatAt instanceof DateTimeImmutable
                ? sprintf(
                    'AgentRun lost heartbeat; last known activity was %s and the run was closed by stale recovery.',
                    $heartbeatAt->format(DATE_ATOM),
                )
                : sprintf(
                    'AgentRun had no attributable heartbeat for %d seconds and was closed by stale recovery.',
                    $staleAfterSeconds,
                );

            $record->recoverStale(
                $message,
                $effectiveFinishedAt,
                $record->technicalRetry(),
            );

            $this->events->append(
                $record->featureId(),
                $record->workflowExecutionId(),
                'WATCHDOG',
                'agent.stale_run_recovered',
                'FAILED',
                $record->agentRole().' stale run closed by recovery',
                [
                    'role' => $record->agentRole(),
                    'stale_after_seconds' => $staleAfterSeconds,
                    'last_heartbeat_at' => $heartbeatAt?->format(DATE_ATOM),
                    'effective_finished_at' => $effectiveFinishedAt->format(DATE_ATOM),
                    'recovered_at' => $now->format(DATE_ATOM),
                    'duration_basis' => $heartbeatAt instanceof DateTimeImmutable ? 'last_heartbeat' : 'stale_timeout',
                ],
                $record->id(),
                $record->traceId(),
                null,
                $message,
            );
            ++$failed;
        }
        if ($failed > 0) $this->entityManager->flush();

        return $failed;
    }

    public function byIdempotencyKey(string $idempotencyKey): ?array
    {
        $record = $this->recordByIdempotencyKey($idempotencyKey);
        return $record instanceof AgentRunRecord ? $this->view($record) : null;
    }

    public function forFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy(
            ['featureId' => $featureId],
            ['startedAt' => 'DESC'],
        );
        return array_map(fn (AgentRunRecord $record): array => $this->view($record), $records);
    }

    public function forWorkflow(string $workflowId): array
    {
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy(
            ['workflowExecutionId' => $workflowId],
            ['startedAt' => 'DESC'],
        );
        return array_map(fn (AgentRunRecord $record): array => $this->view($record), $records);
    }

    private function recordByIdempotencyKey(string $key): ?AgentRunRecord
    {
        $record = $this->entityManager->getRepository(AgentRunRecord::class)->findOneBy(['idempotencyKey' => $key]);
        return $record instanceof AgentRunRecord ? $record : null;
    }

    private function view(AgentRunRecord $record): array
    {
        return [
            'id' => $record->id(),
            'feature_id' => $record->featureId(),
            'workflow_id' => $record->workflowExecutionId(),
            'role' => $record->agentRole(),
            'agent_id' => $record->agentId(),
            'actor_id' => $record->agentId(),
            'execution_id' => $record->id(),
            'status' => $record->status(),
            'task_id' => $record->taskId(),
            'parent_run_id' => $record->parentRunId(),
            'trace_id' => $record->traceId(),
            'input_snapshot' => $record->inputSnapshot(),
            'idempotency_key' => $record->idempotencyKey(),
            'provider' => $record->modelProvider(),
            'model' => $record->model(),
            'output' => $record->output(),
            'runtime_steps' => is_array($record->output()['_runtime_steps'] ?? null) ? $record->output()['_runtime_steps'] : [],
            'tokens_input' => $record->tokensInput(),
            'tokens_output' => $record->tokensOutput(),
            'cost' => $record->estimatedCost() !== null ? (float) $record->estimatedCost() : null,
            'started_at' => $record->startedAt()->format(DATE_ATOM),
            'finished_at' => $record->finishedAt()?->format(DATE_ATOM),
            'error_type' => $record->errorType(),
            'error_message' => $record->errorMessage(),
            'technical_retry' => $record->technicalRetry(),
            'logical_attempt' => $record->logicalAttempt(),
        ];
    }
    /** @param array<string,mixed> $snapshot */
    private function revisionFromSnapshot(array $snapshot): ?string
    {
        foreach (['repository_revision', 'working_revision', 'base_revision'] as $field) {
            $value = trim((string) ($snapshot[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return null;
    }

    private function durationMs(AgentRunRecord $record): ?int
    {
        $finished = $record->finishedAt();
        if ($finished === null) return null;
        $seconds = (float) $finished->format('U.u') - (float) $record->startedAt()->format('U.u');
        return max(0, (int) round($seconds * 1000));
    }

    private function runCorrelationId(string $parentCorrelationId, string $taskId): string
    {
        return mb_substr(rtrim($parentCorrelationId, ':').':agent:'.$taskId, 0, 128);
    }

}
