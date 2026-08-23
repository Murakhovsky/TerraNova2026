<?php
declare(strict_types=1);

namespace Modules\TgAdmin\Services;

use Common\Services\DatabaseService;
use PDO;
use Throwable;

class TelegramAutomationProcessor
{
    private \Closure $sender;

    public function __construct(private DatabaseService $database, callable $sender)
    {
        $this->sender = \Closure::fromCallable($sender);
    }

    public function process(int $limit = 25): array
    {
        $items = $this->claim(max(1, min(100, $limit)));
        $stats = ['claimed' => count($items), 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            try {
                $payload = json_decode((string) $item['payload'], true, 512, JSON_THROW_ON_ERROR);
                $chats = $this->resolveChats($item);
                if (!$chats) {
                    $this->finish((int) $item['id'], (string) $item['lock_token'], 'skipped', 'Немає підключеного отримувача.');
                    $stats['skipped']++;
                    continue;
                }

                $errors = [];
                foreach ($chats as $chatId) {
                    $result = ($this->sender)((int) $chatId, $payload, $item);
                    if ($result !== true) {
                        $errors[] = is_string($result) ? $result : 'Telegram API rejected the message.';
                    }
                }

                if ($errors) {
                    $this->retry((int) $item['id'], (string) $item['lock_token'], (int) $item['attempts'], implode('; ', $errors));
                    $stats['failed']++;
                } else {
                    $this->finish((int) $item['id'], (string) $item['lock_token'], 'sent');
                    $stats['sent']++;
                }
            } catch (Throwable $e) {
                $this->retry((int) $item['id'], (string) $item['lock_token'], (int) $item['attempts'], $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function claim(int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('
                UPDATE tn_notification_outbox
                SET status = "processing", locked_at = NOW(), lock_token = :lock_token, attempts = attempts + 1
                WHERE available_at <= NOW()
                  AND attempts < 5
                  AND (
                      status IN ("pending", "failed")
                      OR (status = "processing" AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                  )
                ORDER BY id
                LIMIT ' . $limit . '
            ');
            $statement->execute(['lock_token' => $token]);
            $fetch = $pdo->prepare('SELECT * FROM tn_notification_outbox WHERE lock_token = :lock_token ORDER BY id');
            $fetch->execute(['lock_token' => $token]);
            $items = $fetch->fetchAll(PDO::FETCH_ASSOC);
            $pdo->commit();
            return $items;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function resolveChats(array $item): array
    {
        if ($item['audience'] === 'chat' && !empty($item['chat_id'])) {
            return [(int) $item['chat_id']];
        }

        $where = 'b.notifications_enabled = 1';
        $params = [];
        switch ((string) $item['audience']) {
            case 'user':
                $where .= ' AND b.user_id = :user_id';
                $params['user_id'] = $item['user_id'];
                break;
            case 'person':
                $where .= ' AND b.person_id = :person_id';
                $params['person_id'] = $item['person_id'];
                break;
            case 'admins':
                $where .= ' AND u.status = "active" AND u.role = "admin"';
                break;
            case 'team':
                $where .= ' AND u.status = "active" AND u.role IN ("manager", "admin")';
                break;
            default:
                return [];
        }

        $rows = $this->database->fetchAll('
            SELECT DISTINCT b.chat_id
            FROM tn_telegram_bindings b
            LEFT JOIN tn_users u ON u.id = b.user_id
            WHERE ' . $where . '
        ', $params);

        return array_values(array_unique(array_map(static fn(array $row): int => (int) $row['chat_id'], $rows)));
    }

    private function finish(int $id, string $lockToken, string $status, ?string $error = null): void
    {
        $statement = $this->database->connection()->prepare('
            UPDATE tn_notification_outbox
            SET status = :status, sent_at = IF(:status = "sent", NOW(), sent_at),
                last_error = :last_error, locked_at = NULL, lock_token = NULL
            WHERE id = :id AND lock_token = :lock_token
            LIMIT 1
        ');
        $statement->execute([
            'id' => $id,
            'lock_token' => $lockToken,
            'status' => $status,
            'last_error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
    }

    private function retry(int $id, string $lockToken, int $attempts, string $error): void
    {
        $terminal = $attempts >= 5;
        $delayMinutes = min(60, 2 ** max(0, $attempts - 1));
        $statement = $this->database->connection()->prepare('
            UPDATE tn_notification_outbox
            SET status = "failed", last_error = :last_error, locked_at = NULL, lock_token = NULL,
                available_at = IF(:terminal = 1, available_at, DATE_ADD(NOW(), INTERVAL ' . $delayMinutes . ' MINUTE))
            WHERE id = :id AND lock_token = :lock_token
            LIMIT 1
        ');
        $statement->execute([
            'id' => $id,
            'lock_token' => $lockToken,
            'terminal' => $terminal ? 1 : 0,
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }
}
