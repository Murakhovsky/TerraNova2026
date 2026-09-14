<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Infrastructure\Property\SalesPropertyReference;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\ClientCaseType;
use Domains\Sales\Model\LeadStatus;
use Domains\Sales\Model\SalesPriority;
use PDO;

final readonly class MysqlClientCaseReadModel implements ClientCaseReadModelInterface
{
    public function __construct(
        private PDO $connection,
        private string $organizationId,
        private SalesPropertyReference $properties,
    ) {
    }

    public function filters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'stage' => $this->canonicalStageFilter((string) ($query['stage'] ?? '')),
            'status' => $this->allowed((string) ($query['status'] ?? ''), ClientCaseStatus::values(), ''),
            'type' => $this->allowed((string) ($query['type'] ?? ''), ClientCaseType::values(), ''),
            'priority' => $this->allowed((string) ($query['priority'] ?? ''), SalesPriority::values(), ''),
            'assigned_user_id' => max(0, (int) ($query['assigned_user_id'] ?? 0)),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['updated', 'newest', 'next_contact', 'budget'], 'updated'),
        ];
    }

    public function cases(array $filters): array
    {
        $where = ['1 = 1'];
        $params = ['organization_id' => $this->organizationId];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(c.public_id LIKE :q OR c.title LIKE :q OR p.full_name LIKE :q OR p.phone LIKE :q OR p.email LIKE :q OR p.telegram LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (($filters['stage'] ?? '') !== '') {
            $where[] = 'COALESCE(ps.code, UPPER(c.stage)) = :stage';
            $params['stage'] = $filters['stage'];
        }
        foreach (['status' => 'status', 'type' => 'type', 'priority' => 'priority'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $where[] = 'c.' . $column . ' = :' . $filter;
                $params[$filter] = $filters[$filter];
            }
        }
        if ((int) ($filters['assigned_user_id'] ?? 0) > 0) {
            $where[] = 'c.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }
        $orderBy = match ($filters['sort'] ?? 'updated') {
            'newest' => 'c.id DESC',
            'next_contact' => 'c.next_contact_at IS NULL, c.next_contact_at ASC, c.updated_at DESC',
            'budget' => 'c.budget_max IS NULL, c.budget_max DESC, c.updated_at DESC',
            default => 'c.updated_at DESC, c.id DESC',
        };

        return $this->all('SELECT c.*, COALESCE(ps.code, UPPER(c.stage)) AS stage_code, COALESCE(ps.name, c.stage) AS stage_name,
            p.public_id AS person_public_id, p.full_name, p.phone, p.email, p.telegram,
            u.full_name AS manager_name, pt.name_uk AS property_type_name, l.city AS location_city,
            (SELECT COUNT(*) FROM tn_leads l WHERE l.client_case_id = c.id AND l.organization_id = c.organization_id) AS inquiry_count,
            (SELECT COUNT(*) FROM tn_client_case_activities a WHERE a.client_case_id = c.id AND a.organization_id = c.organization_id) AS activity_count,
            (SELECT COUNT(*) FROM tn_client_case_property_matches m WHERE m.client_case_id = c.id AND m.organization_id = c.organization_id) AS match_count
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            LEFT JOIN sales_pipeline_stages ps ON ps.id = c.stage_id AND ps.organization_id = c.organization_id
            WHERE c.organization_id = :organization_id AND ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . ' LIMIT 150', $params);
    }

    public function stats(): array
    {
        $stageRows = $this->all('SELECT s.code FROM sales_pipeline_stages s
            INNER JOIN sales_pipelines p ON p.id=s.pipeline_id AND p.organization_id=s.organization_id
            WHERE s.organization_id=:organization_id AND p.status="ACTIVE" ORDER BY p.is_default DESC,s.sort_order,s.id',
            ['organization_id' => $this->organizationId]);
        $stats = ['all' => 0];
        foreach ($stageRows as $stageRow) $stats[(string) $stageRow['code']] = 0;
        $rows = $this->all('SELECT COALESCE(s.code, UPPER(c.stage)) stage_code, COUNT(*) total
            FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id
            WHERE c.organization_id=:organization_id GROUP BY COALESCE(s.code, UPPER(c.stage))',
            ['organization_id' => $this->organizationId]);
        foreach ($rows as $row) {
            $stage = (string) ($row['stage_code'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if ($stage !== '') $stats[$stage] = $total;
            $stats['all'] += $total;
        }
        return $stats;
    }

    public function case(int $id): ?array
    {
        return $this->one('SELECT c.*, COALESCE(ps.code, UPPER(c.stage)) AS stage_code, COALESCE(ps.name, c.stage) AS stage_name,
            pl.name AS pipeline_name, p.public_id AS person_public_id, p.full_name, p.phone, p.email, p.telegram,
            p.notes AS person_notes, u.full_name AS manager_name, pt.name_uk AS property_type_name, l.city AS location_city
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            LEFT JOIN sales_pipelines pl ON pl.id=c.pipeline_id AND pl.organization_id=c.organization_id
            LEFT JOIN sales_pipeline_stages ps ON ps.id=c.stage_id AND ps.organization_id=c.organization_id
            WHERE c.id = :id AND c.organization_id = :organization_id LIMIT 1',
            ['id' => $id, 'organization_id' => $this->organizationId]);
    }

    public function inboundRequests(int $caseId): array
    {
        $rows = $this->all('SELECT l.* FROM tn_leads l
            WHERE l.client_case_id = :case_id AND l.organization_id = :organization_id
            ORDER BY l.created_at DESC, l.id DESC', ['case_id' => $caseId, 'organization_id' => $this->organizationId]);
        return $this->enrichLeadProperties($rows);
    }

    public function activities(int $caseId): array
    {
        return $this->all('SELECT a.*, u.full_name AS user_name FROM tn_client_case_activities a
            LEFT JOIN tn_users u ON u.id = a.user_id AND u.organization_id = a.organization_id
            WHERE a.client_case_id = :case_id AND a.organization_id = :organization_id
            ORDER BY a.created_at DESC, a.id DESC LIMIT 80', ['case_id' => $caseId, 'organization_id' => $this->organizationId]);
    }

    public function propertyMatches(int $caseId): array
    {
        $rows = $this->all('SELECT m.* FROM tn_client_case_property_matches m
            WHERE m.client_case_id = :case_id AND m.organization_id = :organization_id
            ORDER BY FIELD(m.match_status, "interested", "viewing", "sent", "suggested", "deal", "rejected"), m.updated_at DESC',
            ['case_id' => $caseId, 'organization_id' => $this->organizationId]);

        foreach ($rows as &$row) {
            $property = $this->properties->property((int) ($row['property_id'] ?? 0));
            foreach (['public_id','slug','title','price_amount','price_currency','area_total','type_name','city','cover_url'] as $field) {
                $row[$field] = $property[$field] ?? null;
            }
        }
        unset($row);
        return $rows;
    }

    public function requestMatches(int $caseId): array
    {
        return $this->all('SELECT rm.*, l.full_name, l.phone, l.email, l.deal_type, l.status, l.created_at AS request_created_at
            FROM tn_client_case_request_matches rm
            INNER JOIN tn_leads l ON l.id = rm.inbound_request_id AND l.organization_id = rm.organization_id
            WHERE rm.client_case_id = :case_id AND rm.organization_id = :organization_id
            ORDER BY rm.created_at DESC, rm.id DESC', ['case_id' => $caseId, 'organization_id' => $this->organizationId]);
    }

    public function unlinkedInboundRequests(): array
    {
        $rows = $this->all('SELECT l.* FROM tn_leads l
            WHERE l.client_case_id IS NULL AND l.organization_id = :organization_id
            ORDER BY l.created_at DESC, l.id DESC LIMIT 80', ['organization_id' => $this->organizationId]);
        return $this->enrichLeadProperties($rows);
    }

    public function inboundFilters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => $this->allowed((string) ($query['status'] ?? ''), LeadStatus::values(), ''),
            'request_intent' => $this->allowed((string) ($query['request_intent'] ?? ''), $this->requestIntents(), ''),
            'assigned_user_id' => max(0, (int) ($query['assigned_user_id'] ?? 0)),
            'has_case' => $this->allowed((string) ($query['has_case'] ?? ''), ['yes', 'no'], ''),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'next_contact', 'status'], 'newest'),
        ];
    }

    public function inboundInbox(array $filters): array
    {
        $where = ['l.organization_id = :organization_id'];
        $params = ['organization_id' => $this->organizationId];
        if (($filters['q'] ?? '') !== '') {
            $search = ['l.full_name LIKE :q', 'l.phone LIKE :q', 'l.email LIKE :q', 'l.message LIKE :q', 'c.public_id LIKE :q'];
            $params['q'] = '%' . $filters['q'] . '%';
            $propertyPlaceholders = [];
            foreach ($this->properties->searchLegacyPropertyIds((string) $filters['q'], 100) as $index => $propertyId) {
                $key = 'property_q_' . $index;
                $propertyPlaceholders[] = ':' . $key;
                $params[$key] = $propertyId;
            }
            if ($propertyPlaceholders !== []) $search[] = 'l.property_id IN (' . implode(',', $propertyPlaceholders) . ')';
            $where[] = '(' . implode(' OR ', $search) . ')';
        }
        if (($filters['status'] ?? '') !== '') { $where[] = 'l.status = :status'; $params['status'] = $filters['status']; }
        if (($filters['request_intent'] ?? '') !== '') { $where[] = 'l.request_intent = :request_intent'; $params['request_intent'] = $filters['request_intent']; }
        if ((int) ($filters['assigned_user_id'] ?? 0) > 0) { $where[] = 'l.assigned_user_id = :assigned_user_id'; $params['assigned_user_id'] = (int) $filters['assigned_user_id']; }
        if (($filters['has_case'] ?? '') === 'yes') $where[] = 'l.client_case_id IS NOT NULL';
        elseif (($filters['has_case'] ?? '') === 'no') $where[] = 'l.client_case_id IS NULL';
        $orderBy = match ($filters['sort'] ?? 'newest') {
            'next_contact' => 'l.next_contact_at IS NULL, l.next_contact_at ASC, l.created_at DESC',
            'status' => 'FIELD(l.status, "new", "contacted", "qualified", "viewing_planned", "viewing", "negotiation", "won", "lost", "spam", "closed"), l.created_at DESC',
            default => 'l.created_at DESC, l.id DESC',
        };
        $rows = $this->all('SELECT l.*,
            c.public_id AS case_public_id, c.title AS case_title, u.full_name AS manager_name,
            (SELECT COUNT(*) FROM tn_lead_activities activity_count WHERE activity_count.lead_id = l.id) AS activity_count
            FROM tn_leads l
            LEFT JOIN tn_client_cases c ON c.id = l.client_case_id AND c.organization_id = l.organization_id
            LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT 150', $params);
        return $this->enrichLeadProperties($rows);
    }

    public function inboundInboxStats(): array
    {
        $params = ['organization_id' => $this->organizationId];
        $statusRows = $this->all('SELECT status, COUNT(*) AS total FROM tn_leads WHERE organization_id = :organization_id GROUP BY status', $params);
        $intentRows = $this->all('SELECT request_intent, COUNT(*) AS total FROM tn_leads WHERE organization_id = :organization_id GROUP BY request_intent', $params);
        $summary = $this->one('SELECT COUNT(*) AS total, SUM(status = "new") AS new_items,
            SUM(assigned_user_id IS NULL) AS unassigned_items, SUM(client_case_id IS NULL) AS no_case_items,
            SUM(next_contact_at IS NOT NULL AND next_contact_at <= NOW() AND status NOT IN ("won", "lost", "spam", "closed")) AS due_items
            FROM tn_leads WHERE organization_id = :organization_id', $params) ?? [];
        $stats = ['total'=>(int)($summary['total']??0),'new'=>(int)($summary['new_items']??0),
            'unassigned'=>(int)($summary['unassigned_items']??0),'no_case'=>(int)($summary['no_case_items']??0),
            'due'=>(int)($summary['due_items']??0),'status'=>array_fill_keys(LeadStatus::values(),0),'intent'=>array_fill_keys($this->requestIntents(),0)];
        foreach ($statusRows as $row) $stats['status'][(string)$row['status']] = (int)$row['total'];
        foreach ($intentRows as $row) $stats['intent'][(string)$row['request_intent']] = (int)$row['total'];
        return $stats;
    }

    public function leadActivities(int $leadId): array
    {
        return $this->all('SELECT a.*, u.full_name AS user_name FROM tn_lead_activities a
            INNER JOIN tn_leads l ON l.id = a.lead_id AND l.organization_id = :organization_id
            LEFT JOIN tn_users u ON u.id = a.user_id AND u.organization_id = l.organization_id
            WHERE a.lead_id = :lead_id ORDER BY a.created_at DESC, a.id DESC LIMIT 40',
            ['lead_id' => $leadId, 'organization_id' => $this->organizationId]);
    }

    public function openCaseOptions(): array
    {
        return $this->all('SELECT c.id, c.public_id, c.title, c.type, COALESCE(s.code, UPPER(c.stage)) stage, p.full_name
            FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id
            WHERE c.status IN ("active", "paused") AND c.organization_id = :organization_id
            ORDER BY c.updated_at DESC, c.id DESC LIMIT 100', ['organization_id' => $this->organizationId]);
    }

    public function managerOptions(): array
    {
        return $this->all('SELECT id, full_name, email, role FROM tn_users
            WHERE organization_id = :organization_id AND status = "active" AND role IN ("manager", "admin")
            ORDER BY FIELD(role, "admin", "manager"), full_name, email', ['organization_id' => $this->organizationId]);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function enrichLeadProperties(array $rows): array
    {
        foreach ($rows as &$row) {
            $property = $this->properties->property((int) ($row['property_id'] ?? 0));
            $row['property_public_id'] = $property['public_id'] ?? null;
            $row['property_slug'] = $property['slug'] ?? null;
            $row['property_title'] = $property['title'] ?? null;
        }
        unset($row);
        return $rows;
    }

    private function canonicalStageFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        return match (strtolower($value)) {
            'new' => 'NEW', 'contacted' => 'CONTACTED', 'qualification', 'need_defined', 'qualified' => 'QUALIFIED',
            'matching', 'proposal' => 'PROPOSAL', 'viewing', 'meeting' => 'MEETING', 'negotiation' => 'NEGOTIATION',
            'deal', 'aftercare', 'won' => 'WON', 'lost' => 'LOST', default => strtoupper($value),
        };
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function allowed(string $value, array $allowed, string $default): string { return in_array($value, $allowed, true) ? $value : $default; }
    private function requestIntents(): array { return ['general_contact','presentation','viewing','similar_search']; }
}
