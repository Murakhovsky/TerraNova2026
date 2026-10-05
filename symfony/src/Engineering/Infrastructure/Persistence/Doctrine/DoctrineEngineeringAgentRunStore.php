<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Security\EngineeringSecretIsolationGuard;
use App\Engineering\Application\Observability\EngineeringExecutionEventStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\AgentRunRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringAgentRunStore implements EngineeringAgentRunStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringExecutionEventStoreInterface $events,
        private EngineeringSecretIsolationGuard $secrets = new EngineeringSecretIsolationGuard(),
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
        $this->entityManager->persist(new AgentRunRecord(
            id: $runId,
            featureId: $task->featureId,
            workflowExecutionId: $workflowId,
            agentId: strtolower($task->role->value),
            agentRole: $task->role->value,
            idempotencyKey: $task->idempotencyKey,
            modelProvider: 'pending',
            model: 'pending',
            inputSnapshot: $this->secrets->sanitize($task->inputSnapshot),
            status: 'RUNNING',
            technicalRetry: 0,
            logicalAttempt: max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1)),
            traceId: $runTraceId,
            startedAt: new DateTimeImmutable(),
            taskId: $task->id,
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
                'task_id' => $task->id,
                'logical_attempt' => max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1)),
                'objective' => mb_substr($this->secrets->sanitizeText($task->objective), 0, 500),
            ],
            $runId,
            $runTraceId,
        );
        $this->workflows->touchRuntime($workflowId, $runId, $task->id);
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
                'provider' => $result->provider,
                'model' => $result->model,
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
                'cost_amount' => $usage['cost_amount'] ?? null,
                'technical_retries' => $result->technicalRetries,
                'runtime_steps' => count($result->steps),
            ],
            $record->id(),
            $record->traceId(),
            null,
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
                'error_type' => $errorType,
                'technical_retries' => $technicalRetry,
            ],
            $record->id(),
            $record->traceId(),
            null,
            $errorMessage,
        );
        $this->workflows->touchRuntime($record->workflowExecutionId(), $record->id(), $record->taskId());
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        return $this->recordByIdempotencyKey($idempotencyKey) instanceof AgentRunRecord;
    }

    public function failStaleRunning(string $featureId, \App\Engineering\Domain\Agent\AgentRole $role, int $staleAfterSeconds): int
    {
        $staleAfterSeconds = max(60, $staleAfterSeconds);
        $threshold = (new DateTimeImmutable())->modify('-'.$staleAfterSeconds.' seconds');
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy([
            'featureId' => $featureId,
            'agentRole' => $role->value,
            'status' => 'RUNNING',
        ]);

        $failed = 0;
        foreach ($records as $record) {
            if (!$record instanceof AgentRunRecord || $record->startedAt() > $threshold) continue;
            $message = 'AgentRun exceeded the recovery timeout and was closed before a new logical attempt.';
            $record->fail(
                'STALE_RUN_RECOVERY',
                $message,
                $record->technicalRetry(),
            );
            $this->events->append(
                $record->featureId(),
                $record->workflowExecutionId(),
                'WATCHDOG',
                'agent.stale_run_recovered',
                'FAILED',
                $record->agentRole().' stale run closed by recovery',
                ['role' => $record->agentRole(), 'stale_after_seconds' => $staleAfterSeconds],
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
            ['startedAt' => 'ASC'],
        );
        return array_map(fn (AgentRunRecord $record): array => $this->view($record), $records);
    }

    public function forWorkflow(string $workflowId): array
    {
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy(
            ['workflowExecutionId' => $workflowId],
            ['startedAt' => 'ASC'],
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
    private function runCorrelationId(string $parentCorrelationId, string $taskId): string
    {
        return mb_substr(rtrim($parentCorrelationId, ':').':agent:'.$taskId, 0, 128);
    }

}
