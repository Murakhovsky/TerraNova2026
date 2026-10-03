<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Audit;

use App\Engineering\Application\Audit\EngineeringAuditQueryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringAuditQuery implements EngineeringAuditQueryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function forFeature(string $featureId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, workflow_execution_id, from_state, to_state, transition_trigger, reason, initiated_by_type, initiated_by_id, agent_run_id, human_decision_id, metadata, created_at
             FROM cos_engineering_transitions
             WHERE feature_id = :feature_id
             ORDER BY created_at ASC, id ASC',
            ['feature_id' => $featureId],
        );

        return array_map(static function (array $row): array {
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            return [
                'id' => $row['id'],
                'workflow_id' => $row['workflow_execution_id'],
                'from' => $row['from_state'],
                'to' => $row['to_state'],
                'trigger' => $row['transition_trigger'],
                'reason' => $row['reason'],
                'who' => [
                    'type' => $row['initiated_by_type'],
                    'id' => $row['initiated_by_id'],
                ],
                'agent_run_id' => $row['agent_run_id'],
                'human_decision_id' => $row['human_decision_id'],
                'based_on' => is_array($metadata) ? $metadata : [],
                'when' => $row['created_at'],
            ];
        }, $rows);
    }
}
