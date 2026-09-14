<?php
declare(strict_types=1);

namespace Infrastructure\Integration\N8n;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use PDO;
use Throwable;

class IntegrationOutboxProcessor
{
    private \Closure $sender;

    public function __construct(private PdoConnection $database, callable $sender)
    {
        $this->sender = \Closure::fromCallable($sender);
    }

    public function process(int $limit = 25): array
    {
        $items = $this->claim(max(1, min(100, $limit)));
        $stats = ['claimed' => count($items), 'sent' => 0, 'failed' => 0];
        foreach ($items as $item) {
            try {
                $envelope = [
                    'id' => 'tn-outbox-' . $item['id'],
                    'event' => $item['event_type'],
                    'occurred_at' => $item['created_at'],
                    'entity' => ['type' => $item['entity_type'], 'id' => $item['entity_id']],
                    'data' => json_decode((string) $item['payload'], true, 512, JSON_THROW_ON_ERROR),
                ];
                $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $result = ($this->sender)($body, $item);
                if ($result === true) {
                    $this->finish($item, 'sent', $body, null);
                    $stats['sent']++;
                } else {
                    $this->retry($item, $body, is_string($result) ? $result : 'n8n rejected the webhook.');
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                $this->retry($item, '', $e->getMessage());
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
                UPDATE tn_integration_outbox
                SET status = "processing", attempts = attempts + 1, locked_at = NOW(), lock_token = :token
                WHERE integration = "n8n" AND available_at <= NOW() AND attempts < 5
                  AND (status IN ("pending", "failed") OR (status = "processing" AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
                ORDER BY id LIMIT ' . $limit . '
            ');
            $statement->execute(['token' => $token]);
            $fetch = $pdo->prepare('SELECT * FROM tn_integration_outbox WHERE lock_token = :token ORDER BY id');
            $fetch->execute(['token' => $token]);
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

    private function finish(array $item, string $status, string $body, ?string $error): void
    {
        $pdo = $this->database->connection();
        $pdo->prepare('
            UPDATE tn_integration_outbox
            SET status = :status, sent_at = IF(:status = "sent", NOW(), sent_at), last_error = :error,
                locked_at = NULL, lock_token = NULL
            WHERE id = :id AND lock_token = :token LIMIT 1
        ')->execute([
            'id' => $item['id'],
            'token' => $item['lock_token'],
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
        $this->recordDelivery($item, $status === 'sent' ? 'processed' : 'failed', $body, $error);
    }

    private function retry(array $item, string $body, string $error): void
    {
        $delay = min(60, 2 ** max(0, (int) $item['attempts'] - 1));
        $this->database->connection()->prepare('
            UPDATE tn_integration_outbox
            SET status = "failed", last_error = :error, locked_at = NULL, lock_token = NULL,
                available_at = DATE_ADD(NOW(), INTERVAL ' . $delay . ' MINUTE)
            WHERE id = :id AND lock_token = :token LIMIT 1
        ')->execute([
            'id' => $item['id'],
            'token' => $item['lock_token'],
            'error' => mb_substr($error, 0, 2000),
        ]);
        $this->recordDelivery($item, 'failed', $body, $error);
    }

    private function recordDelivery(array $item, string $status, string $body, ?string $error): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_webhook_deliveries (
                integration, direction, event_type, idempotency_key, request_hash, status,
                payload, error_message, processed_at
            ) VALUES (
                "n8n", "outbound", :event_type, :key, :hash, :status, :payload, :error, NOW()
            )
            ON DUPLICATE KEY UPDATE request_hash = VALUES(request_hash), status = VALUES(status),
                payload = VALUES(payload), error_message = VALUES(error_message), processed_at = NOW()
        ')->execute([
            'event_type' => $item['event_type'],
            'key' => 'outbox-' . $item['id'],
            'hash' => hash('sha256', $body),
            'status' => $status,
            'payload' => $body !== '' ? $body : null,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
    }
}
