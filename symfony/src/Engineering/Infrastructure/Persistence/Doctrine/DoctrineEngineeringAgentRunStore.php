<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Persistence\Doctrine\Entity\Engineering\AgentRunRecord;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringAgentRunStore implements EngineeringAgentRunStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function recordCompleted(
        string $workflowId,
        EngineeringAgentTask $task,
        EngineeringAgentRunResult $result,
        string $traceId,
    ): void {
        if ($this->existsByIdempotencyKey($task->idempotencyKey)) return;

        $now = new DateTimeImmutable();
        $usage = $result->usage;
        $this->entityManager->persist(new AgentRunRecord(
            id: $result->runId,
            featureId: $task->featureId,
            workflowExecutionId: $workflowId,
            agentId: strtolower($task->role->value),
            agentRole: $task->role->value,
            idempotencyKey: $task->idempotencyKey,
            modelProvider: $result->provider ?? 'unknown',
            model: $result->model ?? 'unknown',
            inputSnapshot: $task->inputSnapshot,
            status: strtoupper($result->status),
            technicalRetry: 0,
            logicalAttempt: 1,
            traceId: $traceId,
            startedAt: $now,
            output: $result->structuredOutput,
            tokensInput: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            tokensOutput: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            estimatedCost: isset($usage['cost_amount']) ? (string) $usage['cost_amount'] : null,
            finishedAt: $now,
            errorType: $result->error !== null ? 'TASK_ERROR' : null,
            errorMessage: $result->error,
        ));
        $this->entityManager->flush();
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        return $this->entityManager->getRepository(AgentRunRecord::class)->findOneBy(['idempotencyKey' => $idempotencyKey]) instanceof AgentRunRecord;
    }

    public function forFeature(string $featureId): array
    {
        $records = $this->entityManager->getRepository(AgentRunRecord::class)->findBy(
            ['featureId' => $featureId],
            ['startedAt' => 'ASC'],
        );

        return array_map(static fn (AgentRunRecord $record): array => [
            'id' => $record->id(),
            'role' => $record->agentRole(),
            'status' => $record->status(),
            'idempotency_key' => $record->idempotencyKey(),
            'output' => $record->output(),
        ], $records);
    }
}
