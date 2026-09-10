<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use PDO;

final readonly class MysqlSalesRuleContextProvider implements RuleContextProviderInterface
{
    public function __construct(private PDO $connection)
    {
    }

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
                'SELECT c.id, c.public_id, c.status, c.stage, c.pipeline_id, c.stage_id, '
                . 's.code AS stage_code, c.priority, c.next_contact_at, c.assigned_user_id, '
                . 'c.last_activity_at, c.deal_value, c.lost_reason, c.updated_at '
                . 'FROM tn_client_cases c '
                . 'LEFT JOIN sales_pipeline_stages s '
                . 'ON s.id = c.stage_id AND s.organization_id = c.organization_id '
                . 'WHERE c.id = :id AND c.organization_id = :organization_id LIMIT 1'
            );
            $statement->execute([
                'id' => $event->aggregateId,
                'organization_id' => $event->organizationId,
            ]);
            $deal = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($deal !== []) {
                $now = time();
                $lastActivity = isset($deal['last_activity_at'])
                    ? strtotime((string) $deal['last_activity_at'])
                    : false;
                $updatedAt = isset($deal['updated_at'])
                    ? strtotime((string) $deal['updated_at'])
                    : false;

                $deal['no_activity_48h'] = $lastActivity === false || $lastActivity <= $now - 172800;
                $deal['stuck_in_stage'] = $updatedAt !== false && $updatedAt <= $now - 604800;
                $deal['high_value'] = (float) ($deal['deal_value'] ?? 0) >= 100000;
                $deal['lost_reason_missing'] = strtolower((string) ($deal['status'] ?? '')) === 'lost'
                    && trim((string) ($deal['lost_reason'] ?? '')) === '';
            }

            $context['deal'] = $deal;
            $context['client_case'] = $deal;
        } elseif ($event->aggregateType === 'lead') {
            $statement = $this->connection->prepare(
                'SELECT id, status, role, deal_type, client_case_id, property_id, updated_at '
                . 'FROM tn_leads WHERE id = :id AND organization_id = :organization_id LIMIT 1'
            );
            $statement->execute([
                'id' => $event->aggregateId,
                'organization_id' => $event->organizationId,
            ]);
            $context['lead'] = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        return $context;
    }
}
