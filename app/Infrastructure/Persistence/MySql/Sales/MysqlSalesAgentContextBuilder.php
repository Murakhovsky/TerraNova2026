<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use PDO;
use RuntimeException;

final readonly class MysqlSalesAgentContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function build(AgentInvocation $invocation): array
    {
        if (!in_array($invocation->subjectType, ['deal', 'client_case'], true)) {
            throw new RuntimeException(sprintf('Unsupported Sales agent subject: %s.', $invocation->subjectType));
        }
        $statement = $this->connection->prepare(
            'SELECT c.id, c.public_id, c.title, c.status, c.stage, c.priority, c.source, '
            . 'c.budget_min, c.budget_max, c.currency, c.description, c.next_contact_at, c.updated_at, '
            . 'p.full_name, p.notes AS customer_notes FROM tn_client_cases c '
            . 'INNER JOIN tn_people p ON p.id = c.person_id WHERE c.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $invocation->subjectId]);
        $deal = $statement->fetch(PDO::FETCH_ASSOC);
        if ($deal === false) throw new RuntimeException('Sales agent subject was not found.');

        $activities = $this->connection->prepare(
            'SELECT activity_type, title, body, due_at, completed_at, created_at '
            . 'FROM tn_client_case_activities WHERE client_case_id = :id ORDER BY created_at DESC LIMIT 30'
        );
        $activities->execute(['id' => $invocation->subjectId]);

        return [
            'deal' => $deal,
            'recent_activities' => $activities->fetchAll(PDO::FETCH_ASSOC),
            'question' => $invocation->question,
            'context_references' => $invocation->contextReferences,
        ];
    }
}
