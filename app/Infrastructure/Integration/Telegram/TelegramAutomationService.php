<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Telegram;

use Domains\Notification\Application\Contract\TelegramAutomationInterface;
use Infrastructure\Database\Connection\DatabaseService;
use PDO;
use RuntimeException;
use Throwable;

class TelegramAutomationService implements TelegramAutomationInterface
{
    public function __construct(private DatabaseService $database)
    {
    }

    public function createUserLink(int $userId, int $ttlSeconds = 900): array
    {
        $user = $this->database->fetchOne(
            'SELECT id, full_name, role, status FROM tn_users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user || ($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('Активний обліковий запис не знайдено.');
        }

        $token = 'tn_' . bin2hex(random_bytes(24));
        $ttlSeconds = max(300, $ttlSeconds);
        $statement = $this->database->connection()->prepare('
            INSERT INTO tn_telegram_link_tokens (token_hash, user_id, expires_at)
            VALUES (:token_hash, :user_id, DATE_ADD(NOW(), INTERVAL ' . $ttlSeconds . ' SECOND))
        ');
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
        ]);
        $expiresAt = (string) $this->database->connection()->query(
            'SELECT expires_at FROM tn_telegram_link_tokens WHERE id = LAST_INSERT_ID()'
        )->fetchColumn();

        return ['token' => $token, 'expires_at' => $expiresAt, 'user' => $user];
    }

    public function consumeLinkToken(string $token, array $telegram): array
    {
        $telegramUserId = (int) ($telegram['telegram_user_id'] ?? 0);
        $chatId = (int) ($telegram['chat_id'] ?? 0);
        if (!str_starts_with($token, 'tn_') || $telegramUserId === 0 || $chatId === 0) {
            return ['ok' => false, 'message' => 'Посилання для підключення некоректне.'];
        }

        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('
                SELECT t.*, u.full_name, u.role, u.status AS user_status
                FROM tn_telegram_link_tokens t
                LEFT JOIN tn_users u ON u.id = t.user_id
                WHERE t.token_hash = :token_hash
                  AND t.used_at IS NULL
                  AND t.expires_at >= NOW()
                LIMIT 1
                FOR UPDATE
            ');
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $link = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$link || ($link['user_status'] ?? 'active') !== 'active') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Посилання вже використане або протерміноване. Створіть нове в кабінеті.'];
            }

            $upsert = $pdo->prepare('
                INSERT INTO tn_telegram_bindings (
                    user_id, person_id, telegram_user_id, chat_id, username, first_name, last_name,
                    notifications_enabled, verified_at, last_seen_at
                ) VALUES (
                    :user_id, :person_id, :telegram_user_id, :chat_id, :username, :first_name, :last_name,
                    1, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id), person_id = VALUES(person_id), chat_id = VALUES(chat_id),
                    username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name),
                    notifications_enabled = 1, verified_at = NOW(), last_seen_at = NOW()
            ');
            $upsert->execute([
                'user_id' => $link['user_id'] ?: null,
                'person_id' => $link['person_id'] ?: null,
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $chatId,
                'username' => $this->nullable((string) ($telegram['username'] ?? ''), 80),
                'first_name' => $this->nullable((string) ($telegram['first_name'] ?? ''), 120),
                'last_name' => $this->nullable((string) ($telegram['last_name'] ?? ''), 120),
            ]);
            $pdo->prepare('UPDATE tn_telegram_link_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $link['id']]);
            $pdo->commit();

            return ['ok' => true, 'message' => 'Telegram успішно підключено.', 'user' => $link];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logError('telegram-link', $e);
            return ['ok' => false, 'message' => 'Не вдалося підключити Telegram. Спробуйте створити нове посилання.'];
        }
    }

    public function bindingForUser(int $userId): ?array
    {
        return $this->database->fetchOne('
            SELECT id, telegram_user_id, chat_id, username, first_name, last_name,
                   notifications_enabled, verified_at, last_seen_at
            FROM tn_telegram_bindings
            WHERE user_id = :user_id
            ORDER BY verified_at DESC
            LIMIT 1
        ', ['user_id' => $userId]);
    }

    public function bindingForTelegram(int $telegramUserId): ?array
    {
        return $this->database->fetchOne('
            SELECT b.*, u.full_name, u.email, u.role, u.status AS user_status
            FROM tn_telegram_bindings b
            LEFT JOIN tn_users u ON u.id = b.user_id
            WHERE b.telegram_user_id = :telegram_user_id
            LIMIT 1
        ', ['telegram_user_id' => $telegramUserId]);
    }

    public function disconnectUser(int $userId): bool
    {
        $statement = $this->database->connection()->prepare('DELETE FROM tn_telegram_bindings WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        return $statement->rowCount() > 0;
    }

    public function queue(
        string $eventType,
        string $audience,
        array $payload,
        ?int $userId = null,
        ?int $personId = null,
        ?int $chatId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $dedupeKey = null,
        ?string $availableAt = null
    ): bool {
        if (!in_array($audience, ['admins', 'team', 'user', 'person', 'chat'], true)) {
            throw new RuntimeException('Unknown Telegram notification audience.');
        }

        $statement = $this->database->connection()->prepare('
            INSERT IGNORE INTO tn_notification_outbox (
                event_type, audience, user_id, person_id, chat_id, entity_type, entity_id,
                payload, dedupe_key, available_at
            ) VALUES (
                :event_type, :audience, :user_id, :person_id, :chat_id, :entity_type, :entity_id,
                :payload, :dedupe_key, COALESCE(:available_at, NOW())
            )
        ');
        $statement->execute([
            'event_type' => mb_substr($eventType, 0, 64),
            'audience' => $audience,
            'user_id' => $userId,
            'person_id' => $personId,
            'chat_id' => $chatId,
            'entity_type' => $entityType ? mb_substr($entityType, 0, 40) : null,
            'entity_id' => $entityId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'dedupe_key' => $dedupeKey ? mb_substr($dedupeKey, 0, 190) : null,
            'available_at' => $availableAt,
        ]);

        return $statement->rowCount() > 0;
    }

    public function notifyNewInboundRequest(int $requestId): void
    {
        $this->quietly(function () use ($requestId): void {
            $request = $this->database->fetchOne('
                SELECT l.id, l.full_name, l.phone, l.email, l.message, l.property_id,
                       l.client_case_id, c.assigned_user_id, p.title AS property_title
                FROM tn_leads l
                LEFT JOIN tn_client_cases c ON c.id = l.client_case_id
                LEFT JOIN tn_properties p ON p.id = l.property_id
                WHERE l.id = :id LIMIT 1
            ', ['id' => $requestId]);
            if (!$request) {
                return;
            }

            $contact = trim(implode(' / ', array_filter([$request['phone'] ?? null, $request['email'] ?? null])));
            $lines = ['<b>Нова заявка #' . $requestId . '</b>', $this->escape((string) $request['full_name'])];
            if ($contact !== '') {
                $lines[] = $this->escape($contact);
            }
            if (!empty($request['property_title'])) {
                $lines[] = 'Об’єкт: ' . $this->escape((string) $request['property_title']);
            }
            if (!empty($request['message'])) {
                $lines[] = $this->escape(mb_substr((string) $request['message'], 0, 500));
            }

            $assignedUserId = (int) ($request['assigned_user_id'] ?? 0) ?: null;
            $this->queue(
                'inbound_request.created',
                $assignedUserId ? 'user' : 'team',
                ['text' => implode("\n", $lines), 'buttons' => [['Кейс', '/client-case/show/' . (int) ($request['client_case_id'] ?? 0)]]],
                $assignedUserId,
                null,
                null,
                'inbound_request',
                $requestId,
                'inbound-request-created:' . $requestId
            );
        });
    }

    public function notifyPropertySubmission(int $submissionId): void
    {
        $this->quietly(function () use ($submissionId): void {
            $submission = $this->database->fetchOne('
                SELECT id, submission_ref, title, city, owner_name, owner_phone, owner_email
                FROM tn_property_submissions WHERE id = :id LIMIT 1
            ', ['id' => $submissionId]);
            if (!$submission) {
                return;
            }

            $text = '<b>Новий об’єкт на модерації</b>' . "\n"
                . $this->escape((string) $submission['title']) . "\n"
                . $this->escape(trim((string) $submission['city'])) . "\n"
                . $this->escape(trim(implode(' / ', array_filter([$submission['owner_name'], $submission['owner_phone'], $submission['owner_email']]))));
            $this->queue(
                'property_submission.created',
                'team',
                ['text' => trim($text), 'buttons' => [['Модерація', '/property/submission/' . $submissionId]]],
                null,
                null,
                null,
                'property_submission',
                $submissionId,
                'property-submission-created:' . $submissionId
            );
        });
    }

    public function notifySubmissionStatus(int $submissionId, string $status, string $note = '', ?int $propertyId = null): void
    {
        $this->quietly(function () use ($submissionId, $status, $note, $propertyId): void {
            $submission = $this->database->fetchOne('
                SELECT s.title, s.owner_email, u.id AS user_id, pe.id AS person_id, pr.slug AS property_slug
                FROM tn_property_submissions s
                LEFT JOIN tn_users u ON LOWER(u.email) = LOWER(s.owner_email) AND u.status = "active"
                LEFT JOIN tn_people pe ON LOWER(pe.email) = LOWER(s.owner_email)
                LEFT JOIN tn_properties pr ON pr.id = s.property_id
                WHERE s.id = :id LIMIT 1
            ', ['id' => $submissionId]);
            if (!$submission) {
                return;
            }

            $userId = (int) ($submission['user_id'] ?? 0) ?: null;
            $personId = (int) ($submission['person_id'] ?? 0) ?: null;
            if (!$userId && !$personId) {
                return;
            }
            $lines = ['<b>Статус поданого об’єкта змінено</b>', $this->escape((string) $submission['title']), 'Статус: ' . $this->escape($status)];
            if ($note !== '') {
                $lines[] = $this->escape(mb_substr($note, 0, 500));
            }
            $path = $propertyId && !empty($submission['property_slug'])
                ? '/property/show/' . rawurlencode((string) $submission['property_slug'])
                : '/cabinet/submission/' . $submissionId;
            $this->queue(
                'property_submission.status_changed',
                $userId ? 'user' : 'person',
                ['text' => implode("\n", $lines), 'buttons' => [['Відкрити', $path]]],
                $userId,
                $personId,
                null,
                'property_submission',
                $submissionId,
                'property-submission-status:' . $submissionId . ':' . $status
            );
        });
    }

    public function notifyPropertyStatus(int $propertyId, string $status, string $note = ''): void
    {
        $this->quietly(function () use ($propertyId, $status, $note): void {
            $property = $this->database->fetchOne('
                SELECT p.title, p.slug, u.id AS user_id
                FROM tn_properties p
                LEFT JOIN tn_agents a ON a.id = p.agent_id
                LEFT JOIN tn_users u ON LOWER(u.email) = LOWER(a.email) AND u.status = "active"
                WHERE p.id = :id LIMIT 1
            ', ['id' => $propertyId]);
            if (!$property) {
                return;
            }
            $lines = ['<b>Статус об’єкта змінено</b>', $this->escape((string) $property['title']), 'Статус: ' . $this->escape($status)];
            if ($note !== '') {
                $lines[] = $this->escape(mb_substr($note, 0, 500));
            }
            $userId = (int) ($property['user_id'] ?? 0) ?: null;
            $this->queue(
                'property.status_changed',
                $userId ? 'user' : 'team',
                ['text' => implode("\n", $lines), 'buttons' => [['Картка об’єкта', '/property/edit/' . $propertyId]]],
                $userId,
                null,
                null,
                'property',
                $propertyId,
                'property-status:' . $propertyId . ':' . $status . ':' . date('YmdHi')
            );
        });
    }

    public function notifyPresentationShared(int $propertyId, ?int $userId, string $channel, string $variant): void
    {
        if (!$userId) {
            return;
        }
        $this->quietly(function () use ($propertyId, $userId, $channel, $variant): void {
            $property = $this->database->fetchOne('SELECT title FROM tn_properties WHERE id = :id LIMIT 1', ['id' => $propertyId]);
            if (!$property) {
                return;
            }
            $this->queue(
                'property_presentation.shared',
                'user',
                [
                    'text' => '<b>Презентацію зафіксовано в CRM</b>' . "\n" . $this->escape((string) $property['title']) . "\nКанал: " . $this->escape($channel) . ', формат: ' . $this->escape($variant),
                    'buttons' => [['Об’єкт', '/property/edit/' . $propertyId]],
                ],
                $userId,
                null,
                null,
                'property',
                $propertyId
            );
        });
    }

    public function scheduleDueReminders(): int
    {
        $queued = 0;
        $cases = $this->database->fetchAll('
            SELECT c.id, c.public_id, c.title, c.next_contact_at, c.assigned_user_id, p.full_name
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            WHERE c.status = "active" AND c.assigned_user_id IS NOT NULL
              AND c.next_contact_at IS NOT NULL AND c.next_contact_at <= NOW()
            ORDER BY c.next_contact_at ASC LIMIT 100
        ');
        foreach ($cases as $case) {
            $queued += (int) $this->queue(
                'client_case.follow_up_due',
                'user',
                ['text' => '<b>Потрібен контакт із клієнтом</b>' . "\n" . $this->escape((string) $case['full_name']) . "\n" . $this->escape((string) $case['title']), 'buttons' => [['Відкрити кейс', '/client-case/show/' . $case['id']]]],
                (int) $case['assigned_user_id'],
                null,
                null,
                'client_case',
                (int) $case['id'],
                'case-due:' . $case['id'] . ':' . date('Y-m-d', strtotime((string) $case['next_contact_at']))
            );
        }

        $properties = $this->database->fetchAll('
            SELECT p.id, p.title, p.next_action_title, p.next_action_due_at, u.id AS user_id
            FROM tn_properties p
            LEFT JOIN tn_agents a ON a.id = p.agent_id
            LEFT JOIN tn_users u ON LOWER(u.email) = LOWER(a.email) AND u.status = "active"
            WHERE p.status NOT IN ("sold", "archived") AND p.next_action_due_at IS NOT NULL
              AND p.next_action_due_at <= NOW() AND u.id IS NOT NULL
            ORDER BY p.next_action_due_at ASC LIMIT 100
        ');
        foreach ($properties as $property) {
            $queued += (int) $this->queue(
                'property.next_action_due',
                'user',
                ['text' => '<b>Прострочена дія по об’єкту</b>' . "\n" . $this->escape((string) $property['title']) . "\n" . $this->escape((string) ($property['next_action_title'] ?: 'Відкрити картку і запланувати дію')), 'buttons' => [['Відкрити об’єкт', '/property/edit/' . $property['id']]]],
                (int) $property['user_id'],
                null,
                null,
                'property',
                (int) $property['id'],
                'property-due:' . $property['id'] . ':' . date('Y-m-d', strtotime((string) $property['next_action_due_at']))
            );
        }

        return $queued;
    }

    public function queueDailyDigest(): int
    {
        $counts = $this->database->fetchOne('
            SELECT
                (SELECT COUNT(*) FROM tn_leads WHERE status = "new") AS new_requests,
                (SELECT COUNT(*) FROM tn_property_submissions WHERE status IN ("new", "submitted", "review", "in_review")) AS moderation,
                (SELECT COUNT(*) FROM tn_client_cases WHERE status = "active" AND next_contact_at <= NOW()) AS overdue_cases,
                (SELECT COUNT(*) FROM tn_properties WHERE status NOT IN ("sold", "archived") AND next_action_due_at <= NOW()) AS overdue_properties
        ') ?: [];
        $users = $this->database->fetchAll('SELECT id FROM tn_users WHERE status = "active" AND role IN ("manager", "admin")');
        $queued = 0;
        foreach ($users as $user) {
            $text = '<b>Ранковий зріз Terra Nova</b>'
                . "\nНові заявки: " . (int) ($counts['new_requests'] ?? 0)
                . "\nМодерація об’єктів: " . (int) ($counts['moderation'] ?? 0)
                . "\nПрострочені кейси: " . (int) ($counts['overdue_cases'] ?? 0)
                . "\nПрострочені дії по об’єктах: " . (int) ($counts['overdue_properties'] ?? 0);
            $queued += (int) $this->queue(
                'daily_digest',
                'user',
                ['text' => $text, 'buttons' => [['Відкрити кабінет', '/cabinet']]],
                (int) $user['id'],
                null,
                null,
                null,
                null,
                'daily-digest:' . $user['id'] . ':' . date('Y-m-d')
            );
        }
        return $queued;
    }

    public function managerSnapshot(int $telegramUserId): array
    {
        $binding = $this->bindingForTelegram($telegramUserId);
        if (!$binding || ($binding['user_status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'Telegram ще не пов’язаний з активним акаунтом Terra Nova.'];
        }
        if (!in_array((string) $binding['role'], ['manager', 'admin'], true)) {
            return ['ok' => true, 'message' => 'Акаунт підключено. Робочі зведення доступні менеджерам і адміністраторам.'];
        }

        $counts = $this->database->fetchOne('
            SELECT
                (SELECT COUNT(*) FROM tn_leads WHERE status = "new") AS new_requests,
                (SELECT COUNT(*) FROM tn_property_submissions WHERE status IN ("new", "submitted", "review", "in_review")) AS moderation,
                (SELECT COUNT(*) FROM tn_client_cases WHERE status = "active" AND assigned_user_id = :user_id AND next_contact_at <= NOW()) AS my_cases,
                (SELECT COUNT(*) FROM tn_notification_outbox WHERE status = "failed") AS failed_notifications
        ', ['user_id' => $binding['user_id']]) ?: [];

        return [
            'ok' => true,
            'message' => '<b>Робочий стан Terra Nova</b>'
                . "\nНові заявки: " . (int) ($counts['new_requests'] ?? 0)
                . "\nОб’єкти на модерації: " . (int) ($counts['moderation'] ?? 0)
                . "\nМої прострочені контакти: " . (int) ($counts['my_cases'] ?? 0)
                . "\nПомилки доставки: " . (int) ($counts['failed_notifications'] ?? 0),
        ];
    }

    public function outboxStats(): array
    {
        $rows = $this->database->fetchAll('SELECT status, COUNT(*) AS total FROM tn_notification_outbox GROUP BY status');
        $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }
        return $stats;
    }

    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->logError('telegram-queue', $e);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = defined('BASE_PATH') ? BASE_PATH . '/tmp/logs' : dirname(__DIR__, 3) . '/tmp/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($directory . '/telegram.log', sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $label, $error->getMessage()), FILE_APPEND);
    }
}
