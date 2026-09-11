<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use PDO;

final readonly class MysqlSalesRuleContextProvider implements RuleContextProviderInterface
{
    public function __construct(private PDO $connection) {}

    public function contextFor(DomainEvent $event): array
    {
        $context = ['event' => [
            'id' => $event->id, 'type' => $event->type, 'payload' => $event->payload,
            'aggregate_type' => $event->aggregateType, 'aggregate_id' => $event->aggregateId,
        ]];

        if (in_array($event->aggregateType, ['deal', 'client_case'], true)) {
            $statement = $this->connection->prepare(
                'SELECT c.id, c.public_id, c.status, c.stage, c.pipeline_id, c.stage_id, '
                . 's.code AS stage_code, c.priority, c.next_contact_at, c.assigned_user_id, '
                . 'c.source, c.last_activity_at, c.deal_value, c.lost_reason, c.updated_at '
                . 'FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s '
                . 'ON s.id = c.stage_id AND s.organization_id = c.organization_id '
                . 'WHERE c.id = :id AND c.organization_id = :organization_id LIMIT 1'
            );
            $statement->execute(['id' => $event->aggregateId, 'organization_id' => $event->organizationId]);
            $deal = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($deal !== []) {
                $now = time();
                $lastActivity = isset($deal['last_activity_at']) ? strtotime((string) $deal['last_activity_at']) : false;
                $updatedAt = isset($deal['updated_at']) ? strtotime((string) $deal['updated_at']) : false;
                $daysWithoutActivity = $lastActivity === false ? 99999.0 : max(0.0, ($now - $lastActivity) / 86400);
                $deal['value'] = (float) ($deal['deal_value'] ?? 0);
                $deal['owner_id'] = isset($deal['assigned_user_id']) ? (int) $deal['assigned_user_id'] : null;
                $deal['risk'] = $event->payload['risk'] ?? null;
                $deal['days_without_activity'] = $daysWithoutActivity;
                $deal['no_activity_48h'] = $daysWithoutActivity >= 2;
                $deal['stuck_in_stage'] = $updatedAt !== false && $updatedAt <= $now - 604800;
                $deal['high_value'] = (float) ($deal['deal_value'] ?? 0) >= 100000;
                $deal['lost_reason_missing'] = strtolower((string) ($deal['status'] ?? '')) === 'lost'
                    && trim((string) ($deal['lost_reason'] ?? '')) === '';
            }
            $context['deal'] = $deal;
            $context['client_case'] = $deal;
            $context['activity'] = $this->activityContext($event);
        } elseif ($event->aggregateType === 'lead') {
            $statement = $this->connection->prepare(
                'SELECT id, status, role, deal_type, source, assigned_user_id, client_case_id, property_id, updated_at '
                . 'FROM tn_leads WHERE id = :id AND organization_id = :organization_id LIMIT 1'
            );
            $statement->execute(['id' => $event->aggregateId, 'organization_id' => $event->organizationId]);
            $lead = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($lead !== []) $lead['owner_id'] = isset($lead['assigned_user_id']) ? (int) $lead['assigned_user_id'] : null;
            $context['lead'] = $lead;
        }
        return $context;
    }

    /** @return array<string,mixed> */
    private function activityContext(DomainEvent $event): array
    {
        $overdue = in_array($event->type, ['sales.followup.overdue', 'sales.followup.missed'], true);
        return [
            'type' => match ($event->type) {
                'sales.call.completed' => 'call', 'sales.meeting.completed' => 'meeting',
                'sales.followup.overdue', 'sales.followup.missed' => 'followup',
                'sales.message.received', 'sales.message.sent' => 'message',
                default => (string) ($event->payload['activity_type'] ?? 'event'),
            },
            'status' => $overdue ? 'overdue' : (string) ($event->payload['status'] ?? (str_ends_with($event->type, '.completed') ? 'completed' : 'occurred')),
            'completed_at' => $event->payload['completed_at'] ?? null,
            'is_overdue' => $overdue,
        ];
    }
}
