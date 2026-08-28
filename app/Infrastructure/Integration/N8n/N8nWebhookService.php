<?php
declare(strict_types=1);

namespace Infrastructure\Integration\N8n;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Domains\Content\Application\Contract\InboundContentWebhookInterface;
use Infrastructure\Persistence\MySql\Database\Connection\DatabaseService;
use PDOException;
use Throwable;

class N8nWebhookService implements InboundContentWebhookInterface
{
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private DatabaseService $database,
        private ContentServiceInterface $content,
        private string $secret,
        private int $maxClockSkew = 300
    ) {
    }

    public function handle(string $rawBody, string $signature, string $timestamp, string $idempotencyKey): array
    {
        if ($this->secret === '') {
            return $this->response(503, false, 'n8n webhook secret is not configured.');
        }
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->response(413, false, 'Webhook body must not exceed 2 MB.');
        }
        if (!$this->validSignature($rawBody, $signature, $timestamp)) {
            return $this->response(401, false, 'Invalid or expired webhook signature.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 190) {
            return $this->response(422, false, 'X-TN-Idempotency-Key is required.');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->response(400, false, 'Request body must be valid JSON.');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->response(400, false, 'Request body must be a JSON object.');
        }

        $eventType = trim((string) ($payload['event'] ?? ''));
        if (!in_array($eventType, ['content.upsert', 'content.publish', 'content.archive'], true)) {
            return $this->response(422, false, 'Unsupported event type.');
        }
        $requestHash = hash('sha256', $rawBody);
        $existing = $this->database->fetchOne('
            SELECT request_hash, status, response_payload, error_message
            FROM tn_webhook_deliveries
            WHERE integration = "n8n" AND direction = "inbound" AND idempotency_key = :key
            LIMIT 1
        ', ['key' => $idempotencyKey]);
        if ($existing) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                return $this->response(409, false, 'Idempotency key was already used for another payload.');
            }
            $stored = json_decode((string) ($existing['response_payload'] ?? '{}'), true);
            return [
                'status' => ($existing['status'] ?? '') === 'processed' ? 200 : 422,
                'payload' => is_array($stored) && $stored ? $stored : ['ok' => false, 'message' => $existing['error_message']],
            ];
        }

        try {
            $this->database->connection()->prepare('
                INSERT INTO tn_webhook_deliveries (
                    integration, direction, event_type, idempotency_key, request_hash, status, payload
                ) VALUES ("n8n", "inbound", :event_type, :idempotency_key, :request_hash, "received", :payload)
            ')->execute([
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return $this->response(409, false, 'Webhook delivery is already being processed.');
            }
            throw $e;
        }

        try {
            $data = $payload['data'] ?? null;
            if (!is_array($data) || array_is_list($data)) {
                $response = ['ok' => false, 'event' => $eventType, 'message' => 'data must be a JSON object.'];
                $this->finishDelivery($idempotencyKey, 'failed', $response, $response['message']);
                return ['status' => 422, 'payload' => $response];
            }
            if ($eventType === 'content.publish') {
                $data['status'] = 'published';
            } elseif ($eventType === 'content.archive') {
                $data['status'] = 'archived';
            }
            $result = $this->content->upsertFromWebhook($data);
            $httpStatus = !empty($result['ok']) ? 200 : 422;
            $response = [
                'ok' => (bool) ($result['ok'] ?? false),
                'event' => $eventType,
                'content_id' => (int) ($result['id'] ?? 0),
                'slug' => $result['slug'] ?? null,
                'message' => (string) ($result['message'] ?? ''),
            ];
            $this->finishDelivery($idempotencyKey, !empty($result['ok']) ? 'processed' : 'failed', $response, $result['message'] ?? null);
            return ['status' => $httpStatus, 'payload' => $response];
        } catch (Throwable $e) {
            $response = ['ok' => false, 'event' => $eventType, 'message' => 'Webhook processing failed.'];
            $this->finishDelivery($idempotencyKey, 'failed', $response, $e->getMessage());
            return ['status' => 500, 'payload' => $response];
        }
    }

    private function validSignature(string $body, string $signature, string $timestamp): bool
    {
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $this->maxClockSkew) {
            return false;
        }
        $signature = preg_replace('/^sha256=/i', '', trim($signature)) ?: '';
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
        return strlen($signature) === 64 && hash_equals($expected, $signature);
    }

    private function finishDelivery(string $key, string $status, array $response, ?string $error): void
    {
        $this->database->connection()->prepare('
            UPDATE tn_webhook_deliveries
            SET status = :status, response_payload = :response, error_message = :error, processed_at = NOW()
            WHERE integration = "n8n" AND direction = "inbound" AND idempotency_key = :key
            LIMIT 1
        ')->execute([
            'key' => $key,
            'status' => $status,
            'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
    }

    private function response(int $status, bool $ok, string $message): array
    {
        return ['status' => $status, 'payload' => ['ok' => $ok, 'message' => $message]];
    }
}
