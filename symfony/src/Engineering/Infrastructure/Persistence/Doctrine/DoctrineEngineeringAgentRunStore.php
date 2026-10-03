<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Persistence\Doctrine\Entity\Engineering\AgentRunRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringAgentRunStore implements EngineeringAgentRunStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function start(string $workflowId, EngineeringAgentTask $task, string $traceId): string
    {
        $existing = $this->recordByIdempotencyKey($task->idempotencyKey);
        if ($existing instanceof AgentRunRecord) return $existing->id();

        $runId = EngineeringId::generate();
        $this->entityManager->persist(new AgentRunRecord(
            id: $runId,
            featureId: $task->featureId,
            workflowExecutionId: $workflowId,
            agentId: strtolower($task->role->value),
            agentRole: $task->role->value,
            idempotencyKey: $task->idempotencyKey,
            modelProvider: 'pending',
            model: 'pending',
            inputSnapshot: $task->inputSnapshot,
            status: 'RUNNING',
            technicalRetry: 0,
            logicalAttempt: max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1)),
            traceId: $traceId,
            startedAt: new DateTimeImmutable(),
        ));
        $this->entityManager->flush();
        return $runId;
    }

    public function complete(string $engineeringRunId, EngineeringAgentRunResult $result): void
    {
        $record = $this->entityManager->find(AgentRunRecord::class, $engineeringRunId);
        if (!$record instanceof AgentRunRecord) throw new RuntimeException('Engineering AgentRun not found: '.$engineeringRunId);

        $usage = $result->usage;
        $record->complete(
            status: strtoupper($result->status),
            output: array_merge($result->structuredOutput, ['_kernel_run_id' => $result->runId]),
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
    }

    public function fail(string $engineeringRunId, string $errorType, string $errorMessage, int $technicalRetry = 0): void
    {
        $record = $this->entityManager->find(AgentRunRecord::class, $engineeringRunId);
        if (!$record instanceof AgentRunRecord) throw new RuntimeException('Engineering AgentRun not found: '.$engineeringRunId);
        $record->fail($errorType, $errorMessage, $technicalRetry);
        $this->entityManager->flush();
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        return $this->recordByIdempotencyKey($idempotencyKey) instanceof AgentRunRecord;
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
            'status' => $record->status(),
            'idempotency_key' => $record->idempotencyKey(),
            'provider' => $record->modelProvider(),
            'model' => $record->model(),
            'output' => $record->output(),
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
}
