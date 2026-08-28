<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Database\Rule;

use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleEvaluationRepositoryInterface;
use Kernel\Rule\ProcessEvaluation;
use PDO;

final readonly class MysqlRuleEvaluationRepository implements RuleEvaluationRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function save(DomainEvent $event, ProcessEvaluation $evaluation, array $context): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO cos_rule_evaluations '
            . '(id, organization_id, rule_id, event_id, matched, context_snapshot, evaluation_details, correlation_id, evaluated_at) '
            . 'VALUES (:id, :organization_id, :rule_id, :event_id, :matched, :context, :details, :correlation_id, NOW(6))'
        );
        $statement->execute([
            'id' => bin2hex(random_bytes(16)),
            'organization_id' => $event->organizationId,
            'rule_id' => $evaluation->rule->id,
            'event_id' => $event->id,
            'matched' => $evaluation->matched ? 1 : 0,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'details' => json_encode(['rule_version' => $evaluation->rule->version], JSON_THROW_ON_ERROR),
            'correlation_id' => $event->metadata->correlationId,
        ]);
    }
}
