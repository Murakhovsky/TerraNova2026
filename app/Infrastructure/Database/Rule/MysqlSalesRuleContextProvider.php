<?php
declare(strict_types=1);

namespace Infrastructure\Database\Rule;

use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use PDO;

final readonly class MysqlSalesRuleContextProvider implements RuleContextProviderInterface
{
    public function __construct(private PDO $connection) {}

    public function contextFor(DomainEvent $event): array
    {
        $context = ['event' => [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $event->payload,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
        ]];

        if (in_array($event->aggregateType, ['deal', 'client_case'], true)) {
            $statement = $this->connection->prepare(
                'SELECT id, public_id, status, stage, priority, next_contact_at, assigned_user_id, updated_at '
                . 'FROM tn_client_cases WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $event->aggregateId]);
            $deal = $statement->fetch(PDO::FETCH_ASSOC);
            $context['deal'] = $deal ?: [];
            $context['client_case'] = $deal ?: [];
        } elseif ($event->aggregateType === 'lead') {
            $statement = $this->connection->prepare(
                'SELECT id, status, role, deal_type, client_case_id, property_id, updated_at '
                . 'FROM tn_leads WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $event->aggregateId]);
            $context['lead'] = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        return $context;
    }
}
