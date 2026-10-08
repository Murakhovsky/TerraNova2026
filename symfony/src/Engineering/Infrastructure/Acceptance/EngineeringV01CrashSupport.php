<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Acceptance;

use Doctrine\ORM\EntityManagerInterface;

final readonly class EngineeringV01CrashSupport
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function backdateRunningAgentRun(string $featureId, int $seconds = 3600): int
    {
        $startedAt = (new \DateTimeImmutable())->modify('-'.max(120, $seconds).' seconds')->format('Y-m-d H:i:s.u');
        return $this->entityManager->getConnection()->executeStatement(
            "UPDATE cos_engineering_agent_runs
             SET started_at = :started_at
             WHERE feature_id = :feature_id AND status = 'RUNNING'",
            ['started_at' => $startedAt, 'feature_id' => $featureId],
        );
    }

    /** @return array<string,mixed>|null */
    public function runningAgentRun(string $featureId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT id, feature_id, workflow_execution_id, agent_role, status, idempotency_key, logical_attempt, technical_retry, started_at
             FROM cos_engineering_agent_runs
             WHERE feature_id = :feature_id AND status = 'RUNNING'
             ORDER BY started_at DESC
             LIMIT 1",
            ['feature_id' => $featureId],
        );
        return is_array($row) ? $row : null;
    }

    public function workflowState(string $featureId): ?string
    {
        $state = $this->entityManager->getConnection()->fetchOne(
            "SELECT current_state
             FROM cos_engineering_workflows
             WHERE feature_id = :feature_id
             ORDER BY started_at DESC
             LIMIT 1",
            ['feature_id' => $featureId],
        );
        return $state !== false && $state !== null ? (string) $state : null;
    }
}
