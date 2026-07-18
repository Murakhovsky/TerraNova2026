<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use PDO;
use Throwable;

class ClientCaseService
{
    public function __construct(private DatabaseService $database)
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
        $params = [];

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

        return $this->database->fetchAll('
            SELECT
                c.*,
                p.public_id AS person_public_id,
                p.full_name,
                p.phone,
                p.email,
                p.telegram,
                u.full_name AS manager_name,
                pt.name_uk AS property_type_name,
                l.city AS location_city,
                (
                    SELECT COUNT(*) FROM tn_leads lead_count
                    WHERE lead_count.client_case_id = c.id
                ) AS inquiry_count,
                (
                    SELECT COUNT(*) FROM tn_client_case_activities activity_count
                    WHERE activity_count.client_case_id = c.id
                ) AS activity_count,
                (
                    SELECT COUNT(*) FROM tn_client_case_property_matches match_count
                    WHERE match_count.client_case_id = c.id
                ) AS match_count
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . '
            LIMIT 150
        ', $params);
    }

    public function stats(): array
    {
        $rows = $this->database->fetchAll('
            SELECT stage, COUNT(*) AS total
            FROM tn_client_cases
            GROUP BY stage
        ');

        $stats = ['all' => 0];
        foreach ($this->stages() as $stage) {
            $stats[$stage] = 0;
        }

        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (array_key_exists($stage, $stats)) {
                $stats[$stage] = $total;
            }
            $stats['all'] += $total;
        }

        return $stats;
    }

    public function case(int $id): ?array
    {
        return $this->database->fetchOne('
            SELECT
                c.*,
                p.public_id AS person_public_id,
                p.full_name,
                p.phone,
                p.email,
                p.telegram,
                p.notes AS person_notes,
                u.full_name AS manager_name,
                pt.name_uk AS property_type_name,
                l.city AS location_city
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id
            LEFT JOIN tn_property_types pt ON pt.id = c.property_type_id
            LEFT JOIN tn_locations l ON l.id = c.location_id
            WHERE c.id = :id
            LIMIT 1
        ', ['id' => $id]);
    }

    public function inboundRequests(int $caseId): array
    {
        return $this->database->fetchAll('
            SELECT l.*, pr.public_id AS property_public_id, pr.slug AS property_slug, pr.title AS property_title
            FROM tn_leads l
            LEFT JOIN tn_properties pr ON pr.id = l.property_id
            WHERE l.client_case_id = :case_id
            ORDER BY l.created_at DESC, l.id DESC
        ', ['case_id' => $caseId]);
    }

    public function activities(int $caseId): array
    {
        return $this->database->fetchAll('
            SELECT a.*, u.full_name AS user_name
            FROM tn_client_case_activities a
            LEFT JOIN tn_users u ON u.id = a.user_id
            WHERE a.client_case_id = :case_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 80
        ', ['case_id' => $caseId]);
    }

    public function propertyMatches(int $caseId): array
    {
        return $this->database->fetchAll('
            SELECT m.*, p.public_id, p.slug, p.title, p.price_amount, p.price_currency, p.area_total,
                   t.name_uk AS type_name, l.city,
                   COALESCE(cover.image_url, first_image.image_url) AS cover_url
            FROM tn_client_case_property_matches m
            INNER JOIN tn_properties p ON p.id = m.property_id
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE m.client_case_id = :case_id
            ORDER BY FIELD(m.match_status, "interested", "viewing", "sent", "suggested", "deal", "rejected"), m.updated_at DESC
        ', ['case_id' => $caseId]);
    }

    public function requestMatches(int $caseId): array
    {
        return $this->database->fetchAll('
            SELECT rm.*, l.full_name, l.phone, l.email, l.deal_type, l.status, l.created_at AS request_created_at
            FROM tn_client_case_request_matches rm
            INNER JOIN tn_leads l ON l.id = rm.inbound_request_id
            WHERE rm.client_case_id = :case_id
            ORDER BY rm.created_at DESC, rm.id DESC
        ', ['case_id' => $caseId]);
    }

    public function unlinkedInboundRequests(): array
    {
        return $this->database->fetchAll('
            SELECT l.*, p.public_id AS property_public_id, p.title AS property_title
            FROM tn_leads l
            LEFT JOIN tn_properties p ON p.id = l.property_id
            WHERE l.client_case_id IS NULL
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 80
        ');
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
        $where = ['1 = 1'];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(l.full_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR l.message LIKE :q OR p.public_id LIKE :q OR p.title LIKE :q OR c.public_id LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }

        if (($filters['request_intent'] ?? '') !== '') {
            $where[] = 'l.request_intent = :request_intent';
            $params['request_intent'] = $filters['request_intent'];
        }

        if ((int) ($filters['assigned_user_id'] ?? 0) > 0) {
            $where[] = 'l.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }

        if (($filters['has_case'] ?? '') === 'yes') {
            $where[] = 'l.client_case_id IS NOT NULL';
        } elseif (($filters['has_case'] ?? '') === 'no') {
            $where[] = 'l.client_case_id IS NULL';
        }

        $orderBy = match ($filters['sort'] ?? 'newest') {
            'next_contact' => 'l.next_contact_at IS NULL, l.next_contact_at ASC, l.created_at DESC',
            'status' => 'FIELD(l.status, "new", "contacted", "qualified", "viewing", "negotiation", "won", "lost", "spam", "closed"), l.created_at DESC',
            default => 'l.created_at DESC, l.id DESC',
        };

        return $this->database->fetchAll('
            SELECT
                l.*,
                p.public_id AS property_public_id,
                p.slug AS property_slug,
                p.title AS property_title,
                c.public_id AS case_public_id,
                c.title AS case_title,
                u.full_name AS manager_name,
                (
                    SELECT COUNT(*) FROM tn_lead_activities activity_count
                    WHERE activity_count.lead_id = l.id
                ) AS activity_count
            FROM tn_leads l
            LEFT JOIN tn_properties p ON p.id = l.property_id
            LEFT JOIN tn_client_cases c ON c.id = l.client_case_id
            LEFT JOIN tn_users u ON u.id = l.assigned_user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . '
            LIMIT 150
        ', $params);
    }

    public function inboundInboxStats(): array
    {
        $statusRows = $this->database->fetchAll('SELECT status, COUNT(*) AS total FROM tn_leads GROUP BY status');
        $intentRows = $this->database->fetchAll('SELECT request_intent, COUNT(*) AS total FROM tn_leads GROUP BY request_intent');
        $summary = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "new") AS new_items,
                SUM(assigned_user_id IS NULL) AS unassigned_items,
                SUM(client_case_id IS NULL) AS no_case_items,
                SUM(next_contact_at IS NOT NULL AND next_contact_at <= NOW() AND status NOT IN ("won", "lost", "spam", "closed")) AS due_items
            FROM tn_leads
        ') ?? [];

        $stats = [
            'total' => (int) ($summary['total'] ?? 0),
            'new' => (int) ($summary['new_items'] ?? 0),
            'unassigned' => (int) ($summary['unassigned_items'] ?? 0),
            'no_case' => (int) ($summary['no_case_items'] ?? 0),
            'due' => (int) ($summary['due_items'] ?? 0),
            'status' => array_fill_keys($this->leadStatuses(), 0),
            'intent' => array_fill_keys($this->requestIntents(), 0),
        ];

        foreach ($statusRows as $row) {
            $stats['status'][(string) $row['status']] = (int) $row['total'];
        }

        foreach ($intentRows as $row) {
            $stats['intent'][(string) $row['request_intent']] = (int) $row['total'];
        }

        return $stats;
    }

    public function leadActivities(int $leadId): array
    {
        return $this->database->fetchAll('
            SELECT a.*, u.full_name AS user_name
            FROM tn_lead_activities a
            LEFT JOIN tn_users u ON u.id = a.user_id
            WHERE a.lead_id = :lead_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 40
        ', ['lead_id' => $leadId]);
    }

    public function updateInboundRequest(int $requestId, array $input, ?array $user = null): array
    {
        $request = $this->inboundRequest($requestId);
        if (!$request) {
            return ['ok' => false, 'message' => 'Заявку не знайдено.', 'case_id' => null];
        }

        try {
            $status = $this->allowed((string) ($input['status'] ?? $request['status']), $this->leadStatuses(), (string) $request['status']);
            $assignedUserId = array_key_exists('assigned_user_id', $input)
                ? $this->managerId($input['assigned_user_id'])
                : ($request['assigned_user_id'] ?? null);
            $managerNote = $this->nullableText((string) ($input['manager_note'] ?? ($request['manager_note'] ?? '')));
            $nextContactAt = $this->dateTimeOrNull((string) ($input['next_contact_at'] ?? ''));
            $contactedStatuses = ['contacted', 'qualified', 'viewing', 'negotiation', 'won', 'lost'];
            $lastContactedAt = in_array($status, $contactedStatuses, true)
                ? (($request['last_contacted_at'] ?? null) ?: date('Y-m-d H:i:s'))
                : ($request['last_contacted_at'] ?? null);

            $this->database->connection()->prepare('
                UPDATE tn_leads
                SET status = :status,
                    assigned_user_id = :assigned_user_id,
                    manager_note = :manager_note,
                    last_contacted_at = :last_contacted_at,
                    next_contact_at = :next_contact_at,
                    updated_at = NOW()
                WHERE id = :id
            ')->execute([
                'id' => $requestId,
                'status' => $status,
                'assigned_user_id' => $assignedUserId,
                'manager_note' => $managerNote,
                'last_contacted_at' => $lastContactedAt,
                'next_contact_at' => $nextContactAt,
            ]);

            $activityTitle = $this->limit((string) ($input['activity_title'] ?? 'Заявку оновлено'), 180);
            $activityBody = $this->nullableText((string) ($input['activity_body'] ?? ''));
            $activityType = $this->allowed((string) ($input['activity_type'] ?? 'status_change'), $this->leadActivityTypes(), 'status_change');
            $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
            $this->addLeadActivity($requestId, [
                'activity_type' => $activityType,
                'title' => $activityTitle,
                'body' => $activityBody ?: $managerNote,
                'due_at' => $nextContactAt,
                'completed_at' => $completedAt,
            ], $user);

            if (!empty($request['client_case_id'])) {
                $this->syncCaseFromLeadStatus((int) $request['client_case_id'], $status, $assignedUserId, $nextContactAt);
                $this->addActivity((int) $request['client_case_id'], [
                    'activity_type' => $activityType === 'viewing' ? 'viewing' : 'note',
                    'title' => 'Заявку оновлено: ' . $this->leadStatusLabel($status),
                    'body' => $activityBody ?: $managerNote,
                    'due_at' => $nextContactAt,
                    'completed' => $completedAt ? 1 : 0,
                ], $user);
            }

            return ['ok' => true, 'message' => 'Заявку оновлено.', 'case_id' => (int) ($request['client_case_id'] ?? 0)];
        } catch (Throwable $e) {
            $this->logError('client-case-inbound-update', $e);

            return ['ok' => false, 'message' => 'Заявку не вдалося оновити.', 'case_id' => (int) ($request['client_case_id'] ?? 0)];
        }
    }

    public function createCaseFromInboundRequest(int $requestId, array $input = [], ?array $user = null): array
    {
        $request = $this->inboundRequest($requestId);
        if (!$request) {
            return ['ok' => false, 'message' => 'Заявку не знайдено.', 'case_id' => null];
        }

        if (!empty($request['client_case_id'])) {
            return ['ok' => true, 'message' => 'Заявка вже привʼязана до кейса.', 'case_id' => (int) $request['client_case_id']];
        }

        try {
            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $personId = $this->findOrCreatePerson($pdo, [
                'full_name' => (string) $request['full_name'],
                'phone' => $this->nullable((string) ($request['phone'] ?? ''), 50),
                'email' => $this->nullableEmail((string) ($request['email'] ?? '')),
                'telegram' => null,
                'notes' => null,
            ]);

            $caseId = $this->insertCase($pdo, $personId, [
                'full_name' => (string) $request['full_name'],
                'type' => $this->caseTypeFromInbound($request),
                'title' => $this->caseTitleFromInbound($request, (string) $request['full_name']),
                'stage' => 'qualification',
                'status' => 'active',
                'priority' => $this->allowed((string) ($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], 'normal'),
                'assigned_user_id' => $input['assigned_user_id'] ?? ($user['id'] ?? null),
                'source' => $this->nullable((string) ($request['source_page'] ?? 'inbound-request'), 120),
                'description' => $this->nullableText((string) ($request['message'] ?? '')),
                'property_type_id' => $request['property_type_id'] ?? null,
                'location_id' => $request['location_id'] ?? null,
                'currency' => 'USD',
            ], $user);

            $pdo->prepare('
                UPDATE tn_leads
                SET person_id = :person_id,
                    client_case_id = :client_case_id,
                    assigned_user_id = :assigned_user_id,
                    status = "qualified",
                    updated_at = NOW()
                WHERE id = :request_id
            ')->execute([
                'person_id' => $personId,
                'client_case_id' => $caseId,
                'assigned_user_id' => $this->managerId($input['assigned_user_id'] ?? ($user['id'] ?? null)),
                'request_id' => $requestId,
            ]);

            $pdo->prepare('
                INSERT IGNORE INTO tn_client_case_request_matches (client_case_id, inbound_request_id, relation_type)
                VALUES (:client_case_id, :request_id, "source")
            ')->execute(['client_case_id' => $caseId, 'request_id' => $requestId]);

            $pdo->commit();

            $this->addActivity($caseId, [
                'activity_type' => 'note',
                'title' => 'Кейс створено із заявки',
                'body' => $this->nullableText((string) ($request['message'] ?? '')),
            ], $user);

            if (!empty($request['property_id'])) {
                $this->addPropertyMatch($caseId, (int) $request['property_id'], [
                    'match_status' => 'interested',
                    'note' => 'Обʼєкт із вхідної заявки',
                ], $user);
            }

            return ['ok' => true, 'message' => 'Кейс створено із заявки.', 'case_id' => $caseId];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('client-case-create-from-inbound-request', $e);

            return ['ok' => false, 'message' => 'Кейс із заявки не вдалося створити.', 'case_id' => null];
        }
    }

    public function openCaseOptions(): array
    {
        return $this->database->fetchAll('
            SELECT c.id, c.public_id, c.title, c.type, c.stage, p.full_name
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            WHERE c.status IN ("active", "paused")
            ORDER BY c.updated_at DESC, c.id DESC
            LIMIT 100
        ');
    }

    public function managerOptions(): array
    {
        return $this->database->fetchAll('
            SELECT id, full_name, email, role
            FROM tn_users
            WHERE status = "active"
              AND role IN ("manager", "admin")
            ORDER BY FIELD(role, "admin", "manager"), full_name, email
        ');
    }

    public function create(array $input, ?array $user = null): array
    {
        $name = $this->limit((string) ($input['full_name'] ?? ''), 160);
        $phone = $this->nullable((string) ($input['phone'] ?? ''), 50);
        $email = $this->nullableEmail((string) ($input['email'] ?? ''));

        if ($name === '' || (!$phone && !$email)) {
            return ['ok' => false, 'message' => 'Вкажіть імʼя людини і хоча б один контакт.'];
        }

        try {
            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $personId = $this->findOrCreatePerson($pdo, [
                'full_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'telegram' => $this->nullable((string) ($input['telegram'] ?? ''), 80),
                'notes' => $this->nullableText((string) ($input['person_notes'] ?? '')),
            ]);

            $caseId = $this->insertCase($pdo, $personId, $input, $user);

            $pdo->commit();

            $this->addActivity($caseId, [
                'activity_type' => 'note',
                'title' => 'Кейс створено',
                'body' => $this->nullableText((string) ($input['description'] ?? '')),
            ], $user);

            return ['ok' => true, 'message' => 'Кейс створено.', 'case_id' => $caseId];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('client-case-create', $e);

            return ['ok' => false, 'message' => 'Кейс не вдалося створити.'];
        }
    }

    public function update(int $caseId, array $input, ?array $user = null): array
    {
        try {
            $case = $this->case($caseId);
            if (!$case) {
                return ['ok' => false, 'message' => 'Кейс не знайдено.'];
            }

            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $personStatement = $pdo->prepare('
                UPDATE tn_people
                SET full_name = :full_name,
                    phone = :phone,
                    email = :email,
                    telegram = :telegram,
                    notes = :notes
                WHERE id = :id
            ');
            $personStatement->execute([
                'id' => (int) $case['person_id'],
                'full_name' => $this->limit((string) ($input['full_name'] ?? $case['full_name']), 160),
                'phone' => $this->nullable((string) ($input['phone'] ?? ''), 50),
                'email' => $this->nullableEmail((string) ($input['email'] ?? '')),
                'telegram' => $this->nullable((string) ($input['telegram'] ?? ''), 80),
                'notes' => $this->nullableText((string) ($input['person_notes'] ?? '')),
            ]);

            $caseStatement = $pdo->prepare('
                UPDATE tn_client_cases
                SET title = :title,
                    type = :type,
                    status = :status,
                    stage = :stage,
                    priority = :priority,
                    assigned_user_id = :assigned_user_id,
                    source = :source,
                    property_type_id = :property_type_id,
                    location_id = :location_id,
                    budget_min = :budget_min,
                    budget_max = :budget_max,
                    currency = :currency,
                    area_min = :area_min,
                    area_max = :area_max,
                    description = :description,
                    parameters_json = :parameters_json,
                    next_contact_at = :next_contact_at,
                    closed_at = :closed_at
                WHERE id = :id
            ');
            $caseStatement->execute([
                'id' => $caseId,
                'title' => $this->caseTitle($input, (string) ($input['full_name'] ?? $case['full_name'] ?? '')),
                'type' => $this->allowed((string) ($input['type'] ?? 'buy'), $this->types(), 'buy'),
                'status' => $this->allowed((string) ($input['status'] ?? 'active'), ['active', 'paused', 'closed', 'lost'], 'active'),
                'stage' => $this->allowed((string) ($input['stage'] ?? 'new'), $this->stages(), 'new'),
                'priority' => $this->allowed((string) ($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], 'normal'),
                'assigned_user_id' => $this->managerId($input['assigned_user_id'] ?? ($case['assigned_user_id'] ?? null)),
                'source' => $this->nullable((string) ($input['source'] ?? ''), 120),
                'property_type_id' => $this->propertyTypeId($input['property_type_id'] ?? ($case['property_type_id'] ?? null)),
                'location_id' => $this->locationId($input['location_id'] ?? ($case['location_id'] ?? null)),
                'budget_min' => $this->decimal($input['budget_min'] ?? null),
                'budget_max' => $this->decimal($input['budget_max'] ?? null),
                'currency' => $this->currency((string) ($input['currency'] ?? 'USD')),
                'area_min' => $this->decimal($input['area_min'] ?? null),
                'area_max' => $this->decimal($input['area_max'] ?? null),
                'description' => $this->nullableText((string) ($input['description'] ?? '')),
                'parameters_json' => $this->parametersJson($input),
                'next_contact_at' => $this->dateTimeOrNull((string) ($input['next_contact_at'] ?? '')),
                'closed_at' => in_array((string) ($input['status'] ?? ''), ['closed', 'lost'], true) ? date('Y-m-d H:i:s') : null,
            ]);

            $pdo->commit();

            $this->addActivity($caseId, [
                'activity_type' => 'status_change',
                'title' => 'Кейс оновлено',
                'body' => 'Оновлено дані людини або параметри кейсу.',
            ], $user);

            return ['ok' => true, 'message' => 'Кейс оновлено.'];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('client-case-update', $e);

            return ['ok' => false, 'message' => 'Кейс не вдалося оновити.'];
        }
    }

    public function quickUpdate(int $caseId, array $input, ?array $user = null): array
    {
        try {
            $case = $this->case($caseId);
            if (!$case) {
                return ['ok' => false, 'message' => 'Кейс не знайдено.'];
            }

            $status = $this->allowed((string) ($input['status'] ?? $case['status']), ['active', 'paused', 'closed', 'lost'], (string) $case['status']);
            $stage = $this->allowed((string) ($input['stage'] ?? $case['stage']), $this->stages(), (string) $case['stage']);
            $priority = $this->allowed((string) ($input['priority'] ?? $case['priority']), ['low', 'normal', 'high', 'urgent'], (string) $case['priority']);
            $assignedUserId = array_key_exists('assigned_user_id', $input)
                ? $this->managerId($input['assigned_user_id'])
                : ($case['assigned_user_id'] ?? null);
            $nextContactAt = array_key_exists('next_contact_at', $input)
                ? $this->dateTimeOrNull((string) ($input['next_contact_at'] ?? ''))
                : ($case['next_contact_at'] ?? null);

            $this->database->connection()->prepare('
                UPDATE tn_client_cases
                SET status = :status,
                    stage = :stage,
                    priority = :priority,
                    assigned_user_id = :assigned_user_id,
                    next_contact_at = :next_contact_at,
                    closed_at = :closed_at,
                    updated_at = NOW()
                WHERE id = :id
            ')->execute([
                'id' => $caseId,
                'status' => $status,
                'stage' => $stage,
                'priority' => $priority,
                'assigned_user_id' => $assignedUserId,
                'next_contact_at' => $nextContactAt,
                'closed_at' => in_array($status, ['closed', 'lost'], true) ? (($case['closed_at'] ?? null) ?: date('Y-m-d H:i:s')) : null,
            ]);

            $this->addActivity($caseId, [
                'activity_type' => 'status_change',
                'title' => 'Кейс швидко оновлено',
                'body' => 'Оновлено етап, статус, пріоритет або відповідального менеджера.',
            ], $user);

            return ['ok' => true, 'message' => 'Кейс оновлено.'];
        } catch (Throwable $e) {
            $this->logError('client-case-quick-update', $e);

            return ['ok' => false, 'message' => 'Кейс не вдалося оновити.'];
        }
    }

    public function addActivity(int $caseId, array $input, ?array $user = null): array
    {
        $case = $this->case($caseId);
        if (!$case) {
            return ['ok' => false, 'message' => 'Кейс не знайдено.'];
        }

        try {
            $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
            $this->database->connection()->prepare('
                INSERT INTO tn_client_case_activities (client_case_id, person_id, user_id, activity_type, title, body, due_at, completed_at)
                VALUES (:client_case_id, :person_id, :user_id, :activity_type, :title, :body, :due_at, :completed_at)
            ')->execute([
                'client_case_id' => $caseId,
                'person_id' => (int) $case['person_id'],
                'user_id' => $user['id'] ?? null,
                'activity_type' => $this->allowed((string) ($input['activity_type'] ?? 'note'), ['note', 'call', 'message', 'meeting', 'viewing', 'offer', 'status_change', 'deal', 'task'], 'note'),
                'title' => $this->limit((string) ($input['title'] ?? 'Нотатка'), 180),
                'body' => $this->nullableText((string) ($input['body'] ?? '')),
                'due_at' => $this->dateTimeOrNull((string) ($input['due_at'] ?? '')),
                'completed_at' => $completedAt,
            ]);

            if ($completedAt) {
                $this->database->connection()->prepare('
                    UPDATE tn_client_cases
                    SET next_contact_at = NULL
                    WHERE id = :case_id
                ')->execute(['case_id' => $caseId]);
            }

            return ['ok' => true, 'message' => 'Дію додано.'];
        } catch (Throwable $e) {
            $this->logError('client-case-activity', $e);

            return ['ok' => false, 'message' => 'Дію не вдалося додати.'];
        }
    }

    public function attachInboundRequest(int $caseId, int $requestId, ?array $user = null): array
    {
        $case = $this->case($caseId);
        if (!$case) {
            return ['ok' => false, 'message' => 'Кейс не знайдено.'];
        }

        try {
            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $pdo->prepare('
                UPDATE tn_leads
                SET person_id = :person_id,
                    client_case_id = :client_case_id,
                    assigned_user_id = COALESCE(:assigned_user_id, assigned_user_id),
                    status = "qualified",
                    updated_at = NOW()
                WHERE id = :request_id
            ')->execute([
                'person_id' => (int) $case['person_id'],
                'client_case_id' => $caseId,
                'assigned_user_id' => $user['id'] ?? null,
                'request_id' => $requestId,
            ]);

            $pdo->prepare('
                INSERT IGNORE INTO tn_client_case_request_matches (client_case_id, inbound_request_id, relation_type)
                VALUES (:client_case_id, :request_id, "context")
            ')->execute(['client_case_id' => $caseId, 'request_id' => $requestId]);

            $pdo->commit();

            $this->addActivity($caseId, [
                'activity_type' => 'note',
                'title' => 'Заявку привʼязано до кейса',
                'body' => 'Вхідна заявка #' . $requestId . ' додана як контекст кейса.',
            ], $user);

            return ['ok' => true, 'message' => 'Заявку привʼязано до кейсу.'];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('client-case-attach-inbound-request', $e);

            return ['ok' => false, 'message' => 'Заявку не вдалося привʼязати.'];
        }
    }

    public function addPropertyMatch(int $caseId, int $propertyId, array $input, ?array $user = null): array
    {
        $case = $this->case($caseId);
        if (!$case) {
            return ['ok' => false, 'message' => 'Кейс не знайдено.'];
        }

        $property = $this->property($propertyId);
        if (!$property) {
            return ['ok' => false, 'message' => 'Обʼєкт не знайдено.'];
        }

        try {
            $status = $this->allowed((string) ($input['match_status'] ?? 'suggested'), ['suggested', 'sent', 'interested', 'viewing', 'rejected', 'deal'], 'suggested');
            $note = $this->nullable((string) ($input['note'] ?? ''), 500);
            $score = $this->score($input['score'] ?? null);

            $this->database->connection()->prepare('
                INSERT INTO tn_client_case_property_matches (client_case_id, property_id, match_status, score, note)
                VALUES (:client_case_id, :property_id, :match_status, :score, :note)
                ON DUPLICATE KEY UPDATE
                    match_status = VALUES(match_status),
                    score = VALUES(score),
                    note = VALUES(note),
                    updated_at = NOW()
            ')->execute([
                'client_case_id' => $caseId,
                'property_id' => $propertyId,
                'match_status' => $status,
                'score' => $score,
                'note' => $note,
            ]);

            $this->addActivity($caseId, [
                'activity_type' => 'note',
                'title' => 'Обʼєкт додано у підбір',
                'body' => trim($property['public_id'] . ' / ' . $property['title'] . ($note ? ' / ' . $note : '')),
            ], $user);

            return ['ok' => true, 'message' => 'Обʼєкт додано до кейсу.', 'case_id' => $caseId];
        } catch (Throwable $e) {
            $this->logError('client-case-property-match-add', $e);

            return ['ok' => false, 'message' => 'Обʼєкт не вдалося додати до кейсу.'];
        }
    }

    public function updatePropertyMatch(int $matchId, array $input, ?array $user = null): array
    {
        $match = $this->propertyMatch($matchId);
        if (!$match) {
            return ['ok' => false, 'message' => 'Підбір не знайдено.', 'case_id' => null];
        }

        try {
            $status = $this->allowed((string) ($input['match_status'] ?? 'suggested'), ['suggested', 'sent', 'interested', 'viewing', 'rejected', 'deal'], 'suggested');
            $note = $this->nullable((string) ($input['note'] ?? ''), 500);
            $score = $this->score($input['score'] ?? null);

            $this->database->connection()->prepare('
                UPDATE tn_client_case_property_matches
                SET match_status = :match_status,
                    score = :score,
                    note = :note,
                    updated_at = NOW()
                WHERE id = :id
            ')->execute([
                'id' => $matchId,
                'match_status' => $status,
                'score' => $score,
                'note' => $note,
            ]);

            $this->addActivity((int) $match['client_case_id'], [
                'activity_type' => 'note',
                'title' => 'Підбір обʼєкта оновлено',
                'body' => trim(($match['public_id'] ?? '') . ' / ' . ($match['title'] ?? '') . ' / ' . $status),
            ], $user);

            return ['ok' => true, 'message' => 'Підбір оновлено.', 'case_id' => (int) $match['client_case_id']];
        } catch (Throwable $e) {
            $this->logError('client-case-property-match-update', $e);

            return ['ok' => false, 'message' => 'Підбір не вдалося оновити.', 'case_id' => (int) $match['client_case_id']];
        }
    }

    public function registerInboundRequest(int $caseId, int $requestId): void
    {
        try {
            $this->database->connection()->prepare('
                UPDATE tn_client_cases
                SET inbound_request_id = COALESCE(inbound_request_id, :request_id)
                WHERE id = :case_id
            ')->execute(['case_id' => $caseId, 'request_id' => $requestId]);

            $this->database->connection()->prepare('
                INSERT IGNORE INTO tn_client_case_request_matches (client_case_id, inbound_request_id, relation_type)
                VALUES (:case_id, :request_id, "source")
            ')->execute(['case_id' => $caseId, 'request_id' => $requestId]);

            $request = $this->inboundRequest($requestId);
            if ($request) {
                $this->addActivity($caseId, [
                    'activity_type' => 'note',
                    'title' => 'Нова заявка: ' . $this->inboundIntentLabel((string) ($request['request_intent'] ?? 'general_contact')),
                    'body' => $this->nullableText((string) ($request['message'] ?? '')),
                ]);
            }
        } catch (Throwable $e) {
            $this->logError('client-case-register-inbound-request', $e);
        }
    }

    public function addInboundPropertyMatch(int $caseId, int $propertyId): void
    {
        if (!$this->propertyContext($propertyId)) {
            return;
        }

        $this->addPropertyMatch($caseId, $propertyId, [
            'match_status' => 'interested',
            'note' => 'Обʼєкт із вхідної заявки',
        ]);
    }

    public function inboundPropertyId(mixed $value): ?int
    {
        $propertyId = is_numeric($value) ? (int) $value : 0;

        return $this->propertyContext($propertyId) ? $propertyId : null;
    }

    public function ensurePersonAndCaseFromInbound(array $input, ?array $user = null): array
    {
        $name = $this->limit((string) ($input['full_name'] ?? $input['name'] ?? ''), 160);
        $phone = $this->nullable((string) ($input['phone'] ?? ''), 50);
        $email = $this->nullableEmail((string) ($input['email'] ?? ''));

        if ($name === '' || (!$phone && !$email)) {
            return ['person_id' => null, 'client_case_id' => null];
        }

        try {
            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $personId = $this->findOrCreatePerson($pdo, [
                'full_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'telegram' => $this->nullable((string) ($input['telegram'] ?? ''), 80),
                'notes' => null,
            ]);

            $message = $this->nullableText((string) ($input['message'] ?? $input['comment'] ?? ''));
            $propertyContext = $this->propertyContext((int) ($input['property_id'] ?? 0));
            $caseId = $this->insertCase($pdo, $personId, [
                'full_name' => $name,
                'type' => $this->caseTypeFromInbound($input),
                'title' => $this->caseTitleFromInbound($input, $name),
                'stage' => 'new',
                'status' => 'active',
                'priority' => 'normal',
                'source' => 'site-inbound-request',
                'description' => $message,
                'property_type_id' => $propertyContext['type_id'] ?? null,
                'location_id' => $propertyContext['location_id'] ?? null,
                'budget_max' => $propertyContext['price_amount'] ?? null,
                'currency' => $propertyContext['price_currency'] ?? 'USD',
            ], $user);

            $pdo->commit();

            return ['person_id' => $personId, 'client_case_id' => $caseId];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('client-case-ensure-from-inbound', $e);

            return ['person_id' => null, 'client_case_id' => null];
        }
    }

    private function insertCase(PDO $pdo, int $personId, array $input, ?array $user = null): int
    {
        $statement = $pdo->prepare('
            INSERT INTO tn_client_cases (
                public_id, person_id, type, title, status, stage, priority, assigned_user_id, source,
                property_type_id, location_id, budget_min, budget_max, currency, area_min, area_max, description, parameters_json,
                started_at, next_contact_at
            ) VALUES (
                :public_id, :person_id, :type, :title, :status, :stage, :priority, :assigned_user_id, :source,
                :property_type_id, :location_id, :budget_min, :budget_max, :currency, :area_min, :area_max, :description, :parameters_json,
                NOW(), :next_contact_at
            )
        ');
        $statement->execute([
            'public_id' => $this->nextPublicId($pdo, 'tn_client_cases', 'CC'),
            'person_id' => $personId,
            'type' => $this->allowed((string) ($input['type'] ?? 'buy'), $this->types(), 'buy'),
            'title' => $this->caseTitle($input, (string) ($input['full_name'] ?? '')),
            'status' => $this->allowed((string) ($input['status'] ?? 'active'), ['active', 'paused', 'closed', 'lost'], 'active'),
            'stage' => $this->allowed((string) ($input['stage'] ?? 'new'), $this->stages(), 'new'),
            'priority' => $this->allowed((string) ($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], 'normal'),
            'assigned_user_id' => $this->managerId($input['assigned_user_id'] ?? ($user['id'] ?? null)),
            'source' => $this->nullable((string) ($input['source'] ?? 'manual'), 120),
            'property_type_id' => $this->propertyTypeId($input['property_type_id'] ?? null),
            'location_id' => $this->locationId($input['location_id'] ?? null),
            'budget_min' => $this->decimal($input['budget_min'] ?? null),
            'budget_max' => $this->decimal($input['budget_max'] ?? null),
            'currency' => $this->currency((string) ($input['currency'] ?? 'USD')),
            'area_min' => $this->decimal($input['area_min'] ?? null),
            'area_max' => $this->decimal($input['area_max'] ?? null),
            'description' => $this->nullableText((string) ($input['description'] ?? '')),
            'parameters_json' => $this->parametersJson($input),
            'next_contact_at' => $this->dateTimeOrNull((string) ($input['next_contact_at'] ?? '')),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function findOrCreatePerson(PDO $pdo, array $input): int
    {
        $existing = $this->findPerson((string) ($input['email'] ?? ''), (string) ($input['phone'] ?? ''));
        if ($existing) {
            $pdo->prepare('
                UPDATE tn_people
                SET full_name = COALESCE(NULLIF(:full_name, ""), full_name),
                    phone = COALESCE(:phone, phone),
                    email = COALESCE(:email, email),
                    telegram = COALESCE(:telegram, telegram)
                WHERE id = :id
            ')->execute([
                'id' => (int) $existing['id'],
                'full_name' => $input['full_name'] ?? '',
                'phone' => $input['phone'] ?: null,
                'email' => $input['email'] ?: null,
                'telegram' => $input['telegram'] ?: null,
            ]);

            return (int) $existing['id'];
        }

        $statement = $pdo->prepare('
            INSERT INTO tn_people (public_id, full_name, phone, email, telegram, notes)
            VALUES (:public_id, :full_name, :phone, :email, :telegram, :notes)
        ');
        $statement->execute([
            'public_id' => $this->nextPublicId($pdo, 'tn_people', 'PN'),
            'full_name' => $this->limit((string) ($input['full_name'] ?? ''), 160),
            'phone' => $input['phone'] ?: null,
            'email' => $input['email'] ?: null,
            'telegram' => $input['telegram'] ?: null,
            'notes' => $input['notes'] ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function findPerson(string $email, string $phone): ?array
    {
        $email = trim($email);
        $phone = trim($phone);

        if ($email !== '') {
            $person = $this->database->fetchOne('SELECT id FROM tn_people WHERE email = :email LIMIT 1', ['email' => $email]);
            if ($person) {
                return $person;
            }
        }

        if ($phone !== '') {
            return $this->database->fetchOne('SELECT id FROM tn_people WHERE phone = :phone LIMIT 1', ['phone' => $phone]);
        }

        return null;
    }

    private function inboundRequest(int $requestId): ?array
    {
        return $this->database->fetchOne('
            SELECT
                l.*,
                p.type_id AS property_type_id,
                p.location_id,
                p.public_id AS property_public_id,
                p.title AS property_title
            FROM tn_leads l
            LEFT JOIN tn_properties p ON p.id = l.property_id
            WHERE l.id = :id
            LIMIT 1
        ', ['id' => $requestId]);
    }

    private function property(int $propertyId): ?array
    {
        return $this->database->fetchOne('
            SELECT id, public_id, title, slug
            FROM tn_properties
            WHERE id = :id
            LIMIT 1
        ', ['id' => $propertyId]);
    }

    private function propertyContext(int $propertyId): ?array
    {
        if ($propertyId <= 0) {
            return null;
        }

        return $this->database->fetchOne('
            SELECT id, type_id, location_id, price_amount, price_currency
            FROM tn_properties
            WHERE id = :id
              AND status IN ("published", "reserved")
            LIMIT 1
        ', ['id' => $propertyId]);
    }

    private function propertyMatch(int $matchId): ?array
    {
        return $this->database->fetchOne('
            SELECT m.*, p.public_id, p.title
            FROM tn_client_case_property_matches m
            INNER JOIN tn_properties p ON p.id = m.property_id
            WHERE m.id = :id
            LIMIT 1
        ', ['id' => $matchId]);
    }

    private function nextPublicId(PDO $pdo, string $table, string $prefix): string
    {
        $statement = $pdo->query(sprintf(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(public_id, %d) AS UNSIGNED)), 0) + 1 FROM %s WHERE public_id LIKE '%s-%%'",
            strlen($prefix) + 2,
            $table,
            $prefix
        ));
        $number = (int) $statement->fetchColumn();

        return sprintf('%s-%05d', $prefix, $number);
    }

    private function caseTitle(array $input, string $name): string
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title !== '') {
            return $this->limit($title, 220);
        }

        $type = $this->allowed((string) ($input['type'] ?? 'buy'), $this->types(), 'buy');
        $labels = [
            'buy' => 'Купівля',
            'sell' => 'Продаж',
            'rent' => 'Оренда',
            'lease_out' => 'Здача в оренду',
            'repair' => 'Ремонт',
            'investment' => 'Інвестиція',
            'management' => 'Управління',
            'inheritance' => 'Спадщина',
            'other' => 'Кейс',
        ];

        return $this->limit(($labels[$type] ?? 'Кейс') . ($name !== '' ? ': ' . $name : ''), 220);
    }

    private function caseTitleFromInbound(array $input, string $name): string
    {
        $intent = $this->inboundIntentLabel((string) ($input['request_intent'] ?? 'general_contact'));
        $propertyId = (int) ($input['property_id'] ?? 0);
        if ($propertyId > 0) {
            return $this->limit($intent . ' по обʼєкту #' . $propertyId . ': ' . $name, 220);
        }

        return $this->caseTitle(['type' => $this->caseTypeFromInbound($input)], $name);
    }

    private function inboundIntentLabel(string $intent): string
    {
        return [
            'presentation' => 'Презентація',
            'viewing' => 'Перегляд',
            'similar_search' => 'Підбір схожих',
            'general_contact' => 'Загальний контакт',
        ][$intent] ?? 'Заявка';
    }

    private function caseTypeFromInbound(array $input): string
    {
        $role = mb_strtolower(trim((string) ($input['role'] ?? '')));
        $dealType = mb_strtolower(trim((string) ($input['deal_type'] ?? $input['request_type'] ?? '')));

        if ($dealType === 'rent') {
            return in_array($role, ['owner', 'seller'], true) ? 'lease_out' : 'rent';
        }

        if (in_array($role, ['owner', 'seller'], true)) {
            return 'sell';
        }

        if (in_array($role, ['investor'], true) || $dealType === 'investment') {
            return 'investment';
        }

        return 'buy';
    }

    private function leadStatuses(): array
    {
        return ['new', 'contacted', 'qualified', 'viewing', 'negotiation', 'won', 'lost', 'spam', 'closed'];
    }

    private function requestIntents(): array
    {
        return ['general_contact', 'presentation', 'viewing', 'similar_search'];
    }

    private function leadActivityTypes(): array
    {
        return ['note', 'call', 'message', 'status_change', 'task', 'viewing'];
    }

    private function leadStatusLabel(string $status): string
    {
        return [
            'new' => 'Нова',
            'contacted' => 'Контакт був',
            'qualified' => 'Кваліфікована',
            'viewing' => 'Перегляд',
            'negotiation' => 'Переговори',
            'won' => 'Успіх',
            'lost' => 'Втрачена',
            'spam' => 'Спам',
            'closed' => 'Закрита',
        ][$status] ?? 'Заявка';
    }

    private function addLeadActivity(int $leadId, array $input, ?array $user = null): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_lead_activities (lead_id, user_id, activity_type, title, body, due_at, completed_at)
            VALUES (:lead_id, :user_id, :activity_type, :title, :body, :due_at, :completed_at)
        ')->execute([
            'lead_id' => $leadId,
            'user_id' => $user['id'] ?? null,
            'activity_type' => $this->allowed((string) ($input['activity_type'] ?? 'note'), $this->leadActivityTypes(), 'note'),
            'title' => $this->limit((string) ($input['title'] ?? 'Нотатка'), 180),
            'body' => $this->nullableText((string) ($input['body'] ?? '')),
            'due_at' => $this->dateTimeOrNull((string) ($input['due_at'] ?? '')),
            'completed_at' => $input['completed_at'] ?? null,
        ]);
    }

    private function syncCaseFromLeadStatus(int $caseId, string $leadStatus, ?int $assignedUserId, ?string $nextContactAt): void
    {
        $stage = [
            'new' => 'new',
            'contacted' => 'qualification',
            'qualified' => 'qualification',
            'viewing' => 'viewing',
            'negotiation' => 'negotiation',
            'won' => 'deal',
            'lost' => 'lost',
            'spam' => 'lost',
            'closed' => 'lost',
        ][$leadStatus] ?? null;

        if (!$stage) {
            return;
        }

        $status = match ($leadStatus) {
            'won' => 'closed',
            'lost', 'spam', 'closed' => 'lost',
            default => 'active',
        };

        $this->database->connection()->prepare('
            UPDATE tn_client_cases
            SET stage = :stage,
                status = :status,
                assigned_user_id = COALESCE(:assigned_user_id, assigned_user_id),
                next_contact_at = :next_contact_at,
                closed_at = :closed_at,
                updated_at = NOW()
            WHERE id = :id
        ')->execute([
            'id' => $caseId,
            'stage' => $stage,
            'status' => $status,
            'assigned_user_id' => $assignedUserId,
            'next_contact_at' => $nextContactAt,
            'closed_at' => in_array($status, ['closed', 'lost'], true) ? date('Y-m-d H:i:s') : null,
        ]);
    }

    private function managerId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        $row = $this->database->fetchOne('
            SELECT id
            FROM tn_users
            WHERE id = :id
              AND status = "active"
              AND role IN ("manager", "admin")
            LIMIT 1
        ', ['id' => $id]);

        return $row ? (int) $row['id'] : null;
    }

    private function propertyTypeId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        $row = $this->database->fetchOne('
            SELECT id
            FROM tn_property_types
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ', ['id' => $id]);

        return $row ? (int) $row['id'] : null;
    }

    private function locationId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        $row = $this->database->fetchOne('
            SELECT id
            FROM tn_locations
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ', ['id' => $id]);

        return $row ? (int) $row['id'] : null;
    }

    private function parametersJson(array $input): ?string
    {
        $parameters = [];
        foreach (['property_type', 'locations', 'rooms_min', 'must_have', 'nice_to_have'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $parameters[$key] = $value;
            }
        }

        return $parameters ? json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function stages(): array
    {
        return ['new', 'qualification', 'need_defined', 'matching', 'viewing', 'negotiation', 'deal', 'aftercare', 'repeat', 'paused', 'lost'];
    }

    private function types(): array
    {
        return ['buy', 'sell', 'rent', 'lease_out', 'repair', 'investment', 'management', 'inheritance', 'other'];
    }

    private function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function nullableEmail(string $value): ?string
    {
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 160) : null;
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 4000);
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    private function currency(string $value): string
    {
        $value = strtoupper(trim($value));

        return in_array($value, ['USD', 'EUR', 'UAH'], true) ? $value : 'USD';
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function logError(string $label, Throwable|string $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $message = is_string($error) ? $error : $error->getMessage();
        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $message, PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
