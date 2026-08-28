<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\ReadModel;

use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use PDO;

final readonly class MysqlClientCaseReadModel implements ClientCaseReadModelInterface
{
    public function __construct(private PDO $connection, private string $organizationId)
    {
    }

    public function filters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'stage' => $this->allowed((string) ($query['stage'] ?? ''), $this->stages(), ''),
            'status' => $this->allowed((string) ($query['status'] ?? ''), ['active', 'paused', 'closed', 'lost'], ''),
            'type' => $this->allowed((string) ($query['type'] ?? ''), $this->types(), ''),
            'priority' => $this->allowed((string) ($query['priority'] ?? ''), ['low', 'normal', 'high', 'urgent'], ''),
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
        foreach (['stage' => 'stage', 'status' => 'status', 'type' => 'type', 'priority' => 'priority'] as $filter => $column) {
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

        return $this->all('SELECT c.*, p.public_id AS person_public_id, p.full_name, p.phone, p.email, p.telegram,
            u.full_name AS manager_name, pt.name_uk AS property_type_name, l.city AS location_city,
            (SELECT COUNT(*) FROM tn_leads l WHERE l.client_case_id = c.id AND l.organization_id = c.organization_id) AS inquiry_count,
            (SELECT COUNT(*) FROM tn_client_case_activities a WHERE a.client_case_id = c.id AND a.organization_id = c.organization_id) AS activity_count,
            (SELECT COUNT(*) FROM tn_client_case_property_matches m WHERE m.client_case_id = c.id AND m.organization_id = c.organization_id) AS match_count
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            WHERE c.organization_id = :organization_id AND ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . ' LIMIT 150', $params);
    }

    public function stats(): array
    {
        $rows = $this->all('SELECT stage, COUNT(*) AS total FROM tn_client_cases
            WHERE organization_id = :organization_id GROUP BY stage', ['organization_id' => $this->organizationId]);
        $stats = array_fill_keys(['all', ...$this->stages()], 0);
        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (array_key_exists($stage, $stats)) $stats[$stage] = $total;
            $stats['all'] += $total;
        }
        return $stats;
    }

    public function case(int $id): ?array
    {
        return $this->one('SELECT c.*, p.public_id AS person_public_id, p.full_name, p.phone, p.email, p.telegram,
            p.notes AS person_notes, u.full_name AS manager_name, pt.name_uk AS property_type_name, l.city AS location_city
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            WHERE c.id = :id AND c.organization_id = :organization_id LIMIT 1',
            ['id' => $id, 'organization_id' => $this->organizationId]);
    }

    public function inboundRequests(int $caseId): array
    {
        return $this->all('SELECT l.*, pr.public_id AS property_public_id, pr.slug AS property_slug, pr.title AS property_title
            FROM tn_leads l LEFT JOIN tn_properties pr ON pr.id = l.property_id
            WHERE l.client_case_id = :case_id AND l.organization_id = :organization_id
            ORDER BY l.created_at DESC, l.id DESC', ['case_id' => $caseId, 'organization_id' => $this->organizationId]);
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
        return $this->all('SELECT m.*, p.public_id, p.slug, p.title, p.price_amount, p.price_currency, p.area_total,
            t.name_uk AS type_name, l.city, COALESCE(cover.image_url, first_image.image_url) AS cover_url
            FROM tn_client_case_property_matches m
            INNER JOIN tn_properties p ON p.id = m.property_id
            INNER JOIN tn_property_types t ON t.id = p.type_id INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (SELECT i.id FROM tn_property_images i WHERE i.property_id = p.id ORDER BY i.sort_order, i.id LIMIT 1)
            WHERE m.client_case_id = :case_id AND m.organization_id = :organization_id
            ORDER BY FIELD(m.match_status, "interested", "viewing", "sent", "suggested", "deal", "rejected"), m.updated_at DESC',
            ['case_id' => $caseId, 'organization_id' => $this->organizationId]);
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
        return $this->all('SELECT l.*, p.public_id AS property_public_id, p.title AS property_title FROM tn_leads l
            LEFT JOIN tn_properties p ON p.id = l.property_id
            WHERE l.client_case_id IS NULL AND l.organization_id = :organization_id
            ORDER BY l.created_at DESC, l.id DESC LIMIT 80', ['organization_id' => $this->organizationId]);
    }

    public function inboundFilters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => $this->allowed((string) ($query['status'] ?? ''), $this->leadStatuses(), ''),
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
        if (($filters['q'] ?? '') !== '') { $where[] = '(l.full_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR l.message LIKE :q OR p.public_id LIKE :q OR p.title LIKE :q OR c.public_id LIKE :q)'; $params['q'] = '%' . $filters['q'] . '%'; }
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
        return $this->all('SELECT l.*, p.public_id AS property_public_id, p.slug AS property_slug, p.title AS property_title,
            c.public_id AS case_public_id, c.title AS case_title, u.full_name AS manager_name,
            (SELECT COUNT(*) FROM tn_lead_activities activity_count WHERE activity_count.lead_id = l.id) AS activity_count
            FROM tn_leads l LEFT JOIN tn_properties p ON p.id = l.property_id
            LEFT JOIN tn_client_cases c ON c.id = l.client_case_id AND c.organization_id = l.organization_id
            LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT 150', $params);
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
            'due'=>(int)($summary['due_items']??0),'status'=>array_fill_keys($this->leadStatuses(),0),'intent'=>array_fill_keys($this->requestIntents(),0)];
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
        return $this->all('SELECT c.id, c.public_id, c.title, c.type, c.stage, p.full_name
            FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id
            WHERE c.status IN ("active", "paused") AND c.organization_id = :organization_id
            ORDER BY c.updated_at DESC, c.id DESC LIMIT 100', ['organization_id' => $this->organizationId]);
    }

    public function managerOptions(): array
    {
        return $this->all('SELECT id, full_name, email, role FROM tn_users
            WHERE organization_id = :organization_id AND status = "active" AND role IN ("manager", "admin")
            ORDER BY FIELD(role, "admin", "manager"), full_name, email', ['organization_id' => $this->organizationId]);
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
    private function stages(): array { return ['new','qualification','need_defined','matching','viewing','negotiation','deal','aftercare','repeat','paused','lost']; }
    private function types(): array { return ['buy','sell','rent','lease_out','repair','investment','management','inheritance','other']; }
    private function leadStatuses(): array { return ['new','contacted','qualified','viewing_planned','viewing','negotiation','won','lost','spam','closed']; }
    private function requestIntents(): array { return ['general_contact','presentation','viewing','similar_search']; }
}
