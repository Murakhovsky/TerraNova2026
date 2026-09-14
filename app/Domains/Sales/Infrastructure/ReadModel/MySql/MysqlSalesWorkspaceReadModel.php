<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use PDO;

final readonly class MysqlSalesWorkspaceReadModel implements SalesWorkspaceReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function dashboard(string $organizationId, ?int $ownerId = null): array
    {
        $scope = $ownerId !== null ? ' AND c.assigned_user_id = :owner_id' : '';
        $params = ['organization_id' => $organizationId] + ($ownerId !== null ? ['owner_id' => $ownerId] : []);
        $kpis = $this->one(
            'SELECT COUNT(*) active_deals, COALESCE(SUM(COALESCE(c.deal_value, c.budget_max)), 0) pipeline_value, '
            . 'COALESCE(SUM(COALESCE(c.deal_value, c.budget_max) * COALESCE(c.probability, s.probability_default, 0) / 100), 0) expected_revenue, '
            . 'SUM(c.next_contact_at IS NOT NULL AND c.next_contact_at < NOW()) followups_overdue, '
            . 'SUM(c.priority IN ("high", "urgent") OR (c.last_activity_at IS NOT NULL AND c.last_activity_at < NOW() - INTERVAL 48 HOUR)) deals_at_risk '
            . 'FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id = c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.status IN ("active", "paused")' . $scope,
            $params,
        ) ?? [];

        return [
            'kpis' => $kpis,
            'today' => $this->today($organizationId, $ownerId ?? 0),
            'at_risk' => $this->deals($organizationId, ['owner_id' => $ownerId, 'risk' => 'high', 'limit' => 8]),
            'new_leads' => $this->leads($organizationId, ['owner_id' => $ownerId, 'status' => 'new', 'limit' => 8]),
            'metrics' => $this->metrics($organizationId),
        ];
    }

    public function leads(string $organizationId, array $filters = []): array
    {
        $where = ['l.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];
        if (($filters['status'] ?? '') !== '') { $where[] = 'l.status = :status'; $params['status'] = (string) $filters['status']; }
        if (($filters['source'] ?? '') !== '') { $where[] = 'l.source_page = :source'; $params['source'] = (string) $filters['source']; }
        if (($filters['q'] ?? '') !== '') { $where[] = '(l.full_name LIKE :q OR l.email LIKE :q OR l.phone LIKE :q)'; $params['q'] = '%' . trim((string) $filters['q']) . '%'; }
        $owner = (int) ($filters['owner_id'] ?? 0);
        if ($owner > 0) { $where[] = 'l.assigned_user_id = :owner_id'; $params['owner_id'] = $owner; }
        $limit = $this->limit($filters['limit'] ?? 100);
        return $this->all(
            'SELECT l.id, l.full_name name, l.source_page source, l.status, l.assigned_user_id owner_id, u.full_name owner_name, '
            . 'l.created_at, l.last_contacted_at last_contact_at, l.next_contact_at next_action_at, '
            . 'CASE WHEN l.next_contact_at < NOW() THEN "HIGH" WHEN l.status = "new" THEN "MEDIUM" ELSE "NORMAL" END ai_priority, l.client_case_id deal_id '
            . 'FROM tn_leads l LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC LIMIT ' . $limit,
            $params,
        );
    }

    public function deals(string $organizationId, array $filters = []): array
    {
        $where = ['c.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];
        if (($filters['status'] ?? '') !== '') { $where[] = 'c.status = :status'; $params['status'] = (string) $filters['status']; }
        if (($filters['stage_id'] ?? '') !== '') { $where[] = 'c.stage_id = :stage_id'; $params['stage_id'] = (string) $filters['stage_id']; }
        if (($filters['pipeline_id'] ?? '') !== '') { $where[] = 'c.pipeline_id = :pipeline_id'; $params['pipeline_id'] = (string) $filters['pipeline_id']; }
        if (($filters['q'] ?? '') !== '') { $where[] = '(c.title LIKE :q OR c.public_id LIKE :q OR p.full_name LIKE :q)'; $params['q'] = '%' . trim((string) $filters['q']) . '%'; }
        $owner = (int) ($filters['owner_id'] ?? 0);
        if ($owner > 0) { $where[] = 'c.assigned_user_id = :owner_id'; $params['owner_id'] = $owner; }
        if (($filters['risk'] ?? '') === 'high') $where[] = '(c.priority IN ("high", "urgent") OR c.next_contact_at < NOW() OR c.last_activity_at < NOW() - INTERVAL 48 HOUR)';
        $limit = $this->limit($filters['limit'] ?? 100);
        return $this->all(
            'SELECT c.id, c.public_id, c.title, c.status, c.priority, c.pipeline_id, c.stage_id, '
            . 'COALESCE(s.code, UPPER(c.stage)) stage_code, COALESCE(s.name, c.stage) stage_name, s.sort_order stage_order, '
            . 'p.full_name customer, c.assigned_user_id owner_id, u.full_name owner_name, '
            . 'COALESCE(c.deal_value, c.budget_max) deal_value, c.currency, COALESCE(c.probability, s.probability_default) probability, '
            . 'c.last_activity_at, c.next_contact_at next_action_at, c.expected_close_at, c.created_at, c.updated_at, '
            . 'CASE WHEN c.priority IN ("high", "urgent") OR c.next_contact_at < NOW() OR c.last_activity_at < NOW() - INTERVAL 48 HOUR THEN "HIGH" ELSE "NORMAL" END risk_level '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id = c.organization_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(s.sort_order, 0), c.updated_at DESC LIMIT ' . $limit,
            $params,
        );
    }

    public function deal(string $organizationId, int $dealId): ?array
    {
        return $this->one(
            'SELECT c.*, p.full_name customer, p.phone, p.email, p.telegram, u.full_name owner_name, '
            . 'pl.name pipeline_name, COALESCE(s.code, UPPER(c.stage)) stage_code, COALESCE(s.name, c.stage) stage_name, '
            . 'COALESCE(c.deal_value, c.budget_max) value, COALESCE(c.probability, s.probability_default) probability '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipelines pl ON pl.id = c.pipeline_id AND pl.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id = c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.id = :deal_id LIMIT 1',
            ['organization_id' => $organizationId, 'deal_id' => $dealId],
        );
    }

    public function timeline(string $organizationId, int $dealId, int $limit = 100): array
    {
        $limit = $this->limit($limit);
        return $this->all(
            'SELECT * FROM ('
            . 'SELECT CONCAT("activity-", a.id) id, "ACTIVITY" item_type, a.activity_type subtype, a.title, a.body detail, '
            . 'a.created_at occurred_at, NULL correlation_id, NULL status FROM tn_client_case_activities a '
            . 'WHERE a.organization_id = :activity_org AND a.client_case_id = :activity_deal '
            . 'UNION ALL SELECT c.id, "COMMUNICATION", c.channel, CONCAT(c.direction, " · ", c.sender, " → ", c.recipient), c.body, '
            . 'c.occurred_at, NULL, c.direction FROM sales_communications c WHERE c.organization_id = :communication_org AND c.deal_id = :communication_deal '
            . 'UNION ALL SELECT e.id, "EVENT", e.type, e.type, CAST(e.payload AS CHAR), e.occurred_at, e.correlation_id, NULL '
            . 'FROM cos_events e WHERE e.organization_id = :event_org AND e.aggregate_type IN ("deal", "client_case") AND e.aggregate_id = :event_deal '
            . 'UNION ALL SELECT a.id, "ACTION", a.type, a.type, CAST(a.parameters AS CHAR), a.created_at, a.correlation_id, a.status '
            . 'FROM cos_actions a WHERE a.organization_id = :action_org AND a.target_type IN ("deal", "client_case") AND a.target_id = :action_deal'
            . ') timeline ORDER BY occurred_at DESC LIMIT ' . $limit,
            [
                'activity_org' => $organizationId, 'activity_deal' => $dealId,
                'communication_org' => $organizationId, 'communication_deal' => $dealId,
                'event_org' => $organizationId, 'event_deal' => (string) $dealId,
                'action_org' => $organizationId, 'action_deal' => (string) $dealId,
            ],
        );
    }

    public function pipelines(string $organizationId): array
    {
        $pipelines = $this->all('SELECT * FROM sales_pipelines WHERE organization_id = :organization_id AND status = "ACTIVE" ORDER BY is_default DESC, name', ['organization_id' => $organizationId]);
        foreach ($pipelines as &$pipeline) {
            $pipeline['stages'] = $this->all(
                'SELECT * FROM sales_pipeline_stages WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id ORDER BY sort_order, id',
                ['organization_id' => $organizationId, 'pipeline_id' => $pipeline['id']],
            );
        }
        return $pipelines;
    }

    public function today(string $organizationId, int $ownerId): array
    {
        $ownerSql = $ownerId > 0 ? ' AND c.assigned_user_id = :owner_id' : '';
        $base = ['organization_id' => $organizationId] + ($ownerId > 0 ? ['owner_id' => $ownerId] : []);
        $query = fn (string $condition): array => $this->all(
            'SELECT c.id, c.public_id, c.title, p.full_name customer, c.priority, c.next_contact_at, c.last_activity_at '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.status = "active"' . $ownerSql . ' AND ' . $condition
            . ' ORDER BY c.next_contact_at IS NULL, c.next_contact_at, c.updated_at DESC LIMIT 20',
            $base,
        );
        $activityOwnerSql = $ownerId > 0 ? ' AND c.assigned_user_id = :activity_owner_id' : '';
        $activityBase = ['activity_org' => $organizationId] + ($ownerId > 0 ? ['activity_owner_id' => $ownerId] : []);
        $activities = fn (string $type, string $condition): array => $this->all(
            'SELECT c.id, c.public_id, c.title, p.full_name customer, c.priority, a.id activity_id, a.title activity_title, a.due_at '
            . 'FROM tn_client_case_activities a INNER JOIN tn_client_cases c ON c.id=a.client_case_id AND c.organization_id=a.organization_id '
            . 'INNER JOIN tn_people p ON p.id=c.person_id AND p.organization_id=c.organization_id '
            . 'WHERE a.organization_id=:activity_org AND a.activity_type="' . $type . '" AND a.completed_at IS NULL' . $activityOwnerSql . ' AND ' . $condition
            . ' ORDER BY a.due_at IS NULL,a.due_at,c.updated_at DESC LIMIT 20',
            $activityBase,
        );
        $communicationOwnerSql = $ownerId > 0 ? ' AND d.assigned_user_id=:communication_owner_id' : '';
        $communicationParams = ['communication_org' => $organizationId] + ($ownerId > 0 ? ['communication_owner_id' => $ownerId] : []);

        return [
            'must_do' => $query('c.next_contact_at BETWEEN CURRENT_DATE() AND CURRENT_DATE() + INTERVAL 1 DAY'),
            'overdue' => $query('c.next_contact_at < NOW()'),
            'waiting_for_client' => $query('c.next_contact_at IS NULL AND c.last_activity_at >= NOW() - INTERVAL 7 DAY'),
            'new_replies' => $this->safeAll(
                'SELECT d.id,d.public_id,d.title,p.full_name customer,d.priority,c.occurred_at,c.channel,c.body detail '
                . 'FROM sales_communications c INNER JOIN tn_client_cases d ON d.id=c.deal_id AND d.organization_id=c.organization_id '
                . 'INNER JOIN tn_people p ON p.id=d.person_id AND p.organization_id=d.organization_id '
                . 'WHERE c.organization_id=:communication_org AND c.direction="INBOUND" AND c.occurred_at>=NOW()-INTERVAL 24 HOUR' . $communicationOwnerSql
                . ' ORDER BY c.occurred_at DESC LIMIT 20',
                $communicationParams,
            ),
            'meetings' => $activities('meeting', 'a.due_at BETWEEN CURRENT_DATE() AND CURRENT_DATE()+INTERVAL 1 DAY'),
            'followups' => $activities('followup', 'a.due_at <= CURRENT_DATE()+INTERVAL 1 DAY'),
            'ai_recommended' => $this->safeAll(
                'SELECT a.id action_id, a.type, a.target_id deal_id, a.status, a.risk_level, a.parameters, d.reason, d.confidence '
                . 'FROM cos_actions a LEFT JOIN cos_decisions d ON d.source_id = a.source_id '
                . 'WHERE a.organization_id = :organization_id AND a.target_type IN ("deal", "client_case") '
                . 'AND a.status IN ("PROPOSED", "PENDING_APPROVAL", "QUEUED") ORDER BY a.created_at DESC LIMIT 20',
                ['organization_id' => $organizationId],
            ),
        ];
    }

    public function metrics(string $organizationId, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $params = ['organization_id' => $organizationId];
        $core = $this->one(
            'SELECT COUNT(*) total_deals, SUM(c.status = "lost") lost_deals, '
            . 'ROUND(100 * SUM(COALESCE(s.is_won, 0) = 1) / NULLIF(COUNT(*), 0), 2) won_rate, '
            . 'ROUND(AVG(CASE WHEN c.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, c.created_at, c.closed_at) END), 2) sales_cycle_hours '
            . 'FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id=c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.created_at >= NOW() - INTERVAL ' . $days . ' DAY',
            $params,
        ) ?? [];
        $followups = $this->one(
            'SELECT ROUND(100 * SUM(a.completed_at IS NOT NULL) / NULLIF(COUNT(*),0),2) followup_completion_rate '
            . 'FROM tn_client_case_activities a INNER JOIN tn_client_cases c ON c.id=a.client_case_id AND c.organization_id=a.organization_id '
            . 'WHERE a.organization_id=:organization_id AND a.activity_type IN ("task","followup") AND a.created_at>=NOW()-INTERVAL ' . $days . ' DAY',
            $params,
        ) ?? ['followup_completion_rate' => 0];
        $ai = $this->safeOne(
            'SELECT '
            . '(SELECT COUNT(*) FROM cos_actions ca WHERE ca.organization_id = :actions_org AND ca.created_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_proposed, '
            . '(SELECT COUNT(*) FROM cos_actions ca WHERE ca.organization_id = :executed_org AND ca.status = "COMPLETED" AND ca.created_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_executed, '
            . '(SELECT COUNT(*) FROM cos_action_outcomes o WHERE o.organization_id = :outcomes_org AND o.measured_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_successful',
            ['actions_org' => $organizationId, 'executed_org' => $organizationId, 'outcomes_org' => $organizationId],
        ) ?? ['actions_proposed' => 0, 'actions_executed' => 0, 'actions_successful' => 0];
        return $core + $followups + $ai;
    }

    private function limit(mixed $value): int { return max(1, min((int) $value, 250)); }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function safeAll(string $sql, array $params = []): array
    {
        try { return $this->all($sql, $params); } catch (\PDOException) { return []; }
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function safeOne(string $sql, array $params = []): ?array
    {
        try { return $this->one($sql, $params); } catch (\PDOException) { return null; }
    }
}
