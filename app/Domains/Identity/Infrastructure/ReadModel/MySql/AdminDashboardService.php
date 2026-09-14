<?php
declare(strict_types=1);

namespace Domains\Identity\Infrastructure\ReadModel\MySql;

use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Throwable;

class AdminDashboardService implements AdministrationServiceInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function metrics(): array
    {
        $property = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status IN ("published", "active")) AS published,
                SUM(status = "needs_update") AS needs_update,
                SUM(status = "moderation") AS moderation,
                SUM(status = "draft") AS draft,
                SUM(status IN ("reserved", "sold")) AS closed_flow,
                SUM(price_amount IS NULL OR price_amount <= 0) AS without_price,
                SUM(agent_id IS NULL OR agent_id = 0) AS without_agent
            FROM tn_properties
        ') ?? [];

        $submission = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status IN ("new", "submitted")) AS new_items,
                SUM(status IN ("review", "in_review")) AS review_items,
                SUM(status IN ("accepted", "approved", "published")) AS accepted_items,
                SUM(status = "needs_changes") AS needs_changes_items
            FROM tn_property_submissions
        ') ?? [];

        $requests = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "new") AS new_items,
                SUM(status IN ("contacted", "qualified", "viewing_planned", "viewing", "negotiation")) AS contacted_items,
                SUM(client_case_id IS NULL) AS unlinked_items
            FROM tn_leads
        ') ?? [];

        $cases = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "active") AS active_items,
                SUM(priority IN ("high", "urgent") AND status = "active") AS hot_items,
                SUM(next_contact_at IS NOT NULL AND next_contact_at <= NOW() AND status = "active") AS due_items
            FROM tn_client_cases
        ') ?? [];

        $media = $this->database->fetchOne('
            SELECT COUNT(*) AS without_cover
            FROM (
                SELECT p.id
                FROM tn_properties p
                LEFT JOIN tn_property_images i ON i.property_id = p.id
                WHERE p.status IN ("published", "active")
                GROUP BY p.id
                HAVING COUNT(i.id) = 0
            ) missing_media
        ');

        $users = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "active") AS active_items,
                SUM(role IN ("manager", "admin")) AS team_items
            FROM tn_users
        ') ?? [];

        return [
            'properties' => [
                'total' => (int) ($property['total'] ?? 0),
                'published' => (int) ($property['published'] ?? 0),
                'needs_update' => (int) ($property['needs_update'] ?? 0),
                'moderation' => (int) ($property['moderation'] ?? 0),
                'draft' => (int) ($property['draft'] ?? 0),
                'closed_flow' => (int) ($property['closed_flow'] ?? 0),
                'without_price' => (int) ($property['without_price'] ?? 0),
                'without_agent' => (int) ($property['without_agent'] ?? 0),
            ],
            'submissions' => [
                'total' => (int) ($submission['total'] ?? 0),
                'new' => (int) ($submission['new_items'] ?? 0),
                'review' => (int) ($submission['review_items'] ?? 0),
                'accepted' => (int) ($submission['accepted_items'] ?? 0),
                'needs_changes' => (int) ($submission['needs_changes_items'] ?? 0),
            ],
            'requests' => [
                'total' => (int) ($requests['total'] ?? 0),
                'new' => (int) ($requests['new_items'] ?? 0),
                'contacted' => (int) ($requests['contacted_items'] ?? 0),
                'unlinked' => (int) ($requests['unlinked_items'] ?? 0),
            ],
            'cases' => [
                'total' => (int) ($cases['total'] ?? 0),
                'active' => (int) ($cases['active_items'] ?? 0),
                'hot' => (int) ($cases['hot_items'] ?? 0),
                'due' => (int) ($cases['due_items'] ?? 0),
            ],
            'media' => [
                'published_without_cover' => (int) ($media['without_cover'] ?? 0),
            ],
            'users' => [
                'total' => (int) ($users['total'] ?? 0),
                'active' => (int) ($users['active_items'] ?? 0),
                'team' => (int) ($users['team_items'] ?? 0),
            ],
        ];
    }

    public function propertyStatus(): array
    {
        return $this->countBy('tn_properties', 'status');
    }

    public function submissionStatus(): array
    {
        return $this->countBy('tn_property_submissions', 'status');
    }

    public function caseStages(): array
    {
        return $this->database->fetchAll('
            SELECT stage, COUNT(*) AS total
            FROM tn_client_cases
            GROUP BY stage
            ORDER BY FIELD(stage, "new", "qualification", "need_defined", "matching", "viewing", "negotiation", "deal", "aftercare", "repeat", "paused", "lost"), stage
        ');
    }

    public function recentSubmissions(int $limit = 6): array
    {
        return $this->database->fetchAll('
            SELECT id, submission_ref, status, title, city, source_type, created_at
            FROM tn_property_submissions
            ORDER BY FIELD(status, "new", "submitted", "review", "in_review", "needs_changes", "accepted", "approved", "published", "rejected", "spam"), created_at DESC, id DESC
            LIMIT ' . max(1, min(20, $limit))
        );
    }

    public function recentRequests(int $limit = 6): array
    {
        return $this->database->fetchAll('
            SELECT
                l.id, l.full_name, l.phone, l.email, l.deal_type, l.request_intent, l.status, l.source_page, l.created_at,
                p.public_id AS property_public_id,
                p.slug AS property_slug,
                p.title AS property_title,
                c.id AS case_id,
                c.public_id AS case_public_id,
                c.title AS case_title
            FROM tn_leads l
            LEFT JOIN tn_properties p ON p.id = l.property_id
            LEFT JOIN tn_client_cases c ON c.id = l.client_case_id
            ORDER BY FIELD(l.status, "new", "contacted", "qualified", "viewing_planned", "viewing", "negotiation", "won", "lost", "spam", "closed"), l.created_at DESC, l.id DESC
            LIMIT ' . max(1, min(20, $limit))
        );
    }

    public function activeCases(int $limit = 6): array
    {
        return $this->database->fetchAll('
            SELECT c.id, c.public_id, c.title, c.type, c.stage, c.priority, c.next_contact_at, c.updated_at,
                   p.full_name, p.phone, p.email,
                   u.full_name AS manager_name
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            LEFT JOIN tn_users u ON u.id = c.assigned_user_id
            WHERE c.status = "active"
            ORDER BY FIELD(c.priority, "urgent", "high", "normal", "low"), c.next_contact_at IS NULL, c.next_contact_at ASC, c.updated_at DESC
            LIMIT ' . max(1, min(20, $limit))
        );
    }

    public function attentionProperties(int $limit = 8): array
    {
        return $this->database->fetchAll('
            SELECT p.id, p.public_id, p.slug, p.title, p.status, p.updated_at,
                   p.price_amount, p.agent_id, t.name_uk AS type_name, l.city,
                   COUNT(i.id) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images i ON i.property_id = p.id
            GROUP BY p.id
            HAVING p.status IN ("submitted", "moderation", "needs_update", "draft")
                OR image_count = 0
                OR p.price_amount IS NULL
                OR p.price_amount <= 0
                OR p.agent_id IS NULL
            ORDER BY FIELD(p.status, "needs_update", "moderation", "submitted", "draft", "published", "active", "reserved", "sold", "archived"), image_count ASC, p.updated_at DESC
            LIMIT ' . max(1, min(20, $limit))
        );
    }

    public function moderationProperties(int $limit = 6): array
    {
        return $this->propertyListByStatus('moderation', $limit);
    }

    public function activeProperties(int $limit = 6): array
    {
        return $this->propertyListByStatuses(['published', 'active'], $limit);
    }

    public function recentManagerActivities(int $limit = 8): array
    {
        return $this->database->fetchAll('
            SELECT a.id, a.property_id, a.activity_type, a.title, a.body, a.created_at,
                   p.public_id, p.slug, p.title AS property_title,
                   u.full_name AS user_name
            FROM tn_property_activities a
            INNER JOIN tn_properties p ON p.id = a.property_id
            LEFT JOIN tn_users u ON u.id = a.user_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ' . max(1, min(20, $limit))
        );
    }

    public function userFilters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'role' => $this->allowed((string) ($query['role'] ?? ''), $this->userRoles(), ''),
            'status' => $this->allowed((string) ($query['status'] ?? ''), $this->userStatuses(), ''),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'name', 'role', 'last_login'], 'newest'),
        ];
    }

    public function users(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(email LIKE :q OR full_name LIKE :q OR phone LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (($filters['role'] ?? '') !== '') {
            $where[] = 'role = :role';
            $params['role'] = $filters['role'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        $orderBy = match ($filters['sort'] ?? 'newest') {
            'name' => 'full_name ASC, email ASC',
            'role' => 'FIELD(role, "admin", "manager", "developer", "realtor", "partner", "investor", "seller", "buyer"), full_name ASC',
            'last_login' => 'last_login_at IS NULL, last_login_at DESC, updated_at DESC',
            default => 'created_at DESC, id DESC',
        };

        return $this->database->fetchAll('
            SELECT id, email, full_name, phone, role, status, last_login_at, created_at, updated_at
            FROM tn_users
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . '
            LIMIT 250
        ', $params);
    }

    public function userStats(): array
    {
        $summary = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "active") AS active_items,
                SUM(status = "pending") AS pending_items,
                SUM(status = "blocked") AS blocked_items,
                SUM(role IN ("manager", "admin")) AS team_items
            FROM tn_users
        ') ?? [];

        $stats = [
            'total' => (int) ($summary['total'] ?? 0),
            'active' => (int) ($summary['active_items'] ?? 0),
            'pending' => (int) ($summary['pending_items'] ?? 0),
            'blocked' => (int) ($summary['blocked_items'] ?? 0),
            'team' => (int) ($summary['team_items'] ?? 0),
            'roles' => array_fill_keys($this->userRoles(), 0),
        ];

        foreach ($this->database->fetchAll('SELECT role, COUNT(*) AS total FROM tn_users GROUP BY role') as $row) {
            $stats['roles'][(string) $row['role']] = (int) $row['total'];
        }

        return $stats;
    }

    public function createUser(array $input): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['full_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $role = $this->allowed((string) ($input['role'] ?? 'buyer'), $this->userRoles(), 'buyer');
        $status = $this->allowed((string) ($input['status'] ?? 'active'), $this->userStatuses(), 'active');
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $name === '' || $password === '') {
            return ['ok' => false, 'message' => 'Заповніть імʼя, email і пароль.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email.'];
        }

        if (mb_strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Пароль має містити щонайменше 8 символів.'];
        }

        try {
            $this->database->connection()->prepare('
                INSERT INTO tn_users (email, password_hash, full_name, phone, role, status)
                VALUES (:email, :password_hash, :full_name, :phone, :role, :status)
            ')->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => mb_substr($name, 0, 160),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'role' => $role,
                'status' => $status,
            ]);

            return ['ok' => true, 'message' => 'Користувача створено.'];
        } catch (Throwable $e) {
            $this->logError('admin-user-create', $e);

            if (str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'message' => 'Користувач із таким email уже існує.'];
            }

            return ['ok' => false, 'message' => 'Користувача не вдалося створити.'];
        }
    }

    public function updateUser(int $id, array $input, ?array $actor = null): array
    {
        $user = $this->database->fetchOne('SELECT id, email, role, status FROM tn_users WHERE id = :id LIMIT 1', ['id' => $id]);
        if (!$user) {
            return ['ok' => false, 'message' => 'Користувача не знайдено.'];
        }

        $role = $this->allowed((string) ($input['role'] ?? $user['role']), $this->userRoles(), (string) $user['role']);
        $status = $this->allowed((string) ($input['status'] ?? $user['status']), $this->userStatuses(), (string) $user['status']);
        $name = trim((string) ($input['full_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $isSelf = (int) ($actor['id'] ?? 0) === $id;

        if ($name === '') {
            return ['ok' => false, 'message' => 'Імʼя користувача не може бути порожнім.'];
        }

        if ($isSelf && ($role !== 'admin' || $status !== 'active')) {
            return ['ok' => false, 'message' => 'Не можна зняти власний повний доступ або заблокувати свій акаунт.'];
        }

        if ($password !== '' && mb_strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Новий пароль має містити щонайменше 8 символів.'];
        }

        try {
            $params = [
                'id' => $id,
                'full_name' => mb_substr($name, 0, 160),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'role' => $role,
                'status' => $status,
            ];
            $passwordSql = '';

            if ($password !== '') {
                $passwordSql = ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $this->database->connection()->prepare('
                UPDATE tn_users
                SET full_name = :full_name,
                    phone = :phone,
                    role = :role,
                    status = :status' . $passwordSql . '
                WHERE id = :id
                LIMIT 1
            ')->execute($params);

            return ['ok' => true, 'message' => 'Користувача оновлено.'];
        } catch (Throwable $e) {
            $this->logError('admin-user-update', $e);

            return ['ok' => false, 'message' => 'Користувача не вдалося оновити.'];
        }
    }

    private function propertyListByStatus(string $status, int $limit): array
    {
        return $this->database->fetchAll('
            SELECT p.id, p.public_id, p.slug, p.title, p.status, p.updated_at,
                   p.price_amount, p.price_currency, p.price_period,
                   t.name_uk AS type_name, l.city,
                   COUNT(i.id) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images i ON i.property_id = p.id
            WHERE p.status = :status
            GROUP BY p.id
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT ' . max(1, min(20, $limit))
        , ['status' => $status]);
    }

    private function propertyListByStatuses(array $statuses, int $limit): array
    {
        $statuses = array_values(array_filter($statuses, static fn($status) => is_string($status) && $status !== ''));
        if (!$statuses) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($statuses as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }

        return $this->database->fetchAll('
            SELECT p.id, p.public_id, p.slug, p.title, p.status, p.updated_at,
                   p.price_amount, p.price_currency, p.price_period,
                   t.name_uk AS type_name, l.city,
                   COUNT(i.id) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images i ON i.property_id = p.id
            WHERE p.status IN (' . implode(', ', $placeholders) . ')
            GROUP BY p.id
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT ' . max(1, min(20, $limit))
        , $params);
    }

    private function countBy(string $table, string $column): array
    {
        return $this->database->fetchAll(sprintf(
            'SELECT %s AS item, COUNT(*) AS total FROM %s GROUP BY %s ORDER BY total DESC, %s',
            $column,
            $table,
            $column,
            $column
        ));
    }

    private function userRoles(): array
    {
        return ['buyer', 'seller', 'investor', 'realtor', 'developer', 'partner', 'manager', 'admin'];
    }

    private function userStatuses(): array
    {
        return ['active', 'blocked', 'pending'];
    }

    private function allowed(string $value, array $allowed, string $default): string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $error->getMessage(), PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
