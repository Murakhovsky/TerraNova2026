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
                . 'c.source, c.last_activity_at, c.deal_value, c.lost_reason, c.updated_at, '
                . 't.stuck_after_seconds, h.entered_at AS current_stage_entered_at, h.history_quality '
                . 'FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s '
                . 'ON CONVERT(s.id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(c.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'AND CONVERT(s.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'LEFT JOIN sales_stage_metric_thresholds t '
                . 'ON CONVERT(t.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'AND CONVERT(t.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(c.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'LEFT JOIN sales_deal_stage_history h ON h.id = (SELECT h2.id FROM sales_deal_stage_history h2 '
                . 'WHERE CONVERT(h2.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'AND CONVERT(h2.deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
                . 'CONVERT(CAST(c.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'AND h2.left_at IS NULL '
                . 'ORDER BY COALESCE(h2.entered_at,h2.projected_at) DESC,h2.id DESC LIMIT 1) '
                . 'WHERE c.id = :id AND c.organization_id = :organization_id LIMIT 1'
            );
            $statement->execute(['id' => $event->aggregateId, 'organization_id' => $event->organizationId]);
            $deal = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($deal !== []) {
                $now = time();
                $lastActivity = isset($deal['last_activity_at']) ? strtotime((string) $deal['last_activity_at']) : false;
                $enteredAt = isset($deal['current_stage_entered_at']) ? strtotime((string) $deal['current_stage_entered_at']) : false;
                $nextContact = isset($deal['next_contact_at']) ? strtotime((string) $deal['next_contact_at']) : false;
                $threshold = isset($deal['stuck_after_seconds']) ? (int) $deal['stuck_after_seconds'] : 0;
                $daysWithoutActivity = $lastActivity === false ? 99999.0 : max(0.0, ($now - $lastActivity) / 86400);
                $hasExactStageEntry = $enteredAt !== false && ($deal['history_quality'] ?? null) !== 'ESTIMATED';
                $stageAgeBreached = $threshold > 0 && $hasExactStageEntry && ($now - $enteredAt) >= $threshold;
                $activityBreached = $threshold > 0 && ($lastActivity === false || ($now - $lastActivity) >= $threshold);
                $hasFutureContact = $nextContact !== false && $nextContact > $now;

                $deal['value'] = (float) ($deal['deal_value'] ?? 0);
                $deal['owner_id'] = isset($deal['assigned_user_id']) ? (int) $deal['assigned_user_id'] : null;
                $deal['risk'] = $event->payload['risk'] ?? null;
                $deal['days_without_activity'] = $daysWithoutActivity;
                $deal['no_activity_48h'] = $daysWithoutActivity >= 2;
                $deal['stuck_in_stage'] = $stageAgeBreached && $activityBreached && !$hasFutureContact;
                $deal['high_value'] = (float) ($deal['deal_value'] ?? 0) >= 100000;
                $deal['lost_reason_missing'] = strtolower((string) ($deal['status'] ?? '')) === 'lost'
                    && trim((string) ($deal['lost_reason'] ?? '')) === '';
            }
            $context['deal'] = $deal;
            $context['client_case'] = $deal;
            $context['activity'] = $this->activityContext($event);
        } elseif ($event->aggregateType === 'lead') {
            $statement = $this->connection->prepare(
                'SELECT id, status, role, deal_type, source_page AS source, assigned_user_id, client_case_id, property_id, updated_at '
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
