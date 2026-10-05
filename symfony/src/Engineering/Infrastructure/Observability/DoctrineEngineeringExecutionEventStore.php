<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Observability;

use App\Engineering\Application\Observability\EngineeringExecutionEventStoreInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringExecutionEventStore implements EngineeringExecutionEventStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function append(
        string $featureId,
        string $workflowId,
        string $category,
        string $action,
        string $status,
        string $summary,
        array $details = [],
        ?string $agentRunId = null,
        ?string $correlationId = null,
        ?int $durationMs = null,
        ?string $error = null,
    ): void {
        $this->entityManager->getConnection()->insert('cos_engineering_execution_events', [
            'id' => EngineeringId::generate(),
            'feature_id' => EngineeringId::assert($featureId),
            'workflow_execution_id' => EngineeringId::assert($workflowId),
            'agent_run_id' => $agentRunId !== null ? EngineeringId::assert($agentRunId) : null,
            'correlation_id' => $correlationId !== null && trim($correlationId) !== '' ? mb_substr(trim($correlationId), 0, 128) : null,
            'category' => mb_substr(strtoupper(trim($category)), 0, 32),
            'action' => mb_substr(trim($action), 0, 128),
            'status' => mb_substr(strtoupper(trim($status)), 0, 24),
            'summary' => mb_substr(trim($summary), 0, 500),
            'details' => $details !== [] ? json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'duration_ms' => $durationMs,
            'error' => $error !== null ? mb_substr($error, 0, 4000) : null,
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function forWorkflow(string $workflowId, int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, feature_id, workflow_execution_id, agent_run_id, correlation_id, category, action, status,
                    summary, details, duration_ms, error, occurred_at
             FROM cos_engineering_execution_events
             WHERE workflow_execution_id = :workflow_id
             ORDER BY occurred_at DESC, id DESC
             LIMIT '.$limit,
            ['workflow_id' => EngineeringId::assert($workflowId)],
        );

        return array_map(static function (array $row): array {
            $details = json_decode((string) ($row['details'] ?? '{}'), true);
            return [
                'id' => (string) $row['id'],
                'feature_id' => (string) $row['feature_id'],
                'workflow_id' => (string) $row['workflow_execution_id'],
                'agent_run_id' => $row['agent_run_id'] !== null ? (string) $row['agent_run_id'] : null,
                'correlation_id' => $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
                'category' => (string) $row['category'],
                'action' => (string) $row['action'],
                'status' => (string) $row['status'],
                'summary' => (string) $row['summary'],
                'details' => is_array($details) ? $details : [],
                'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }, $rows);
    }
}
