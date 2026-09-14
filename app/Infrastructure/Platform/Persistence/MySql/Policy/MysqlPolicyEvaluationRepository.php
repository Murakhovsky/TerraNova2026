<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Policy;

use Kernel\Action\Action;
use Kernel\Policy\Contract\PolicyEvaluationRepositoryInterface;
use Kernel\Policy\PolicyEvaluation;
use PDO;

final readonly class MysqlPolicyEvaluationRepository implements PolicyEvaluationRepositoryInterface
{
    public function __construct(private PDO $connection) {}
    public function save(Action $action, PolicyEvaluation $evaluation, array $context): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_policy_evaluations (id, organization_id, action_id, policy_id, decision, reason, '
            . 'context_snapshot, correlation_id, evaluated_at) VALUES (:id, :organization_id, :action_id, '
            . ':policy_id, :decision, :reason, :context, :correlation_id, NOW(6))'
        );
        $statement->execute([
            'id' => bin2hex(random_bytes(16)), 'organization_id' => $action->organizationId,
            'action_id' => $action->id, 'policy_id' => $evaluation->policy?->id,
            'decision' => $evaluation->decision->value, 'reason' => $evaluation->reason,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'correlation_id' => $action->correlationId,
        ]);
    }
}
