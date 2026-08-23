<?php
declare(strict_types=1);

use Common\Services\DatabaseService;
use Modules\Frontend\Services\ContentService;
use Modules\Frontend\Services\IntegrationOutboxProcessor;
use Modules\Frontend\Services\N8nWebhookService;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
require APP_PATH . '/config/loader.php';
$config = require APP_PATH . '/config/config.php';
$database = new DatabaseService($config->database);
$pdo = $database->connection();
$content = new ContentService($database);
$secret = bin2hex(random_bytes(24));
$externalId = 'test-n8n-' . bin2hex(random_bytes(6));
$idempotencyKey = 'test-delivery-' . bin2hex(random_bytes(6));
$contentId = 0;
$outboxId = 0;

try {
    $payload = [
        'event' => 'content.publish',
        'data' => [
            'external_id' => $externalId,
            'content_type' => 'blog_post',
            'title' => 'Тестова стаття n8n',
            'slug' => 'test-n8n-' . bin2hex(random_bytes(4)),
            'excerpt' => 'Перевірка webhook, публікації та SEO.',
            'body_html' => '<h2>Безпечний контент</h2><p>Текст статті.</p><script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(1)">',
            'meta_title' => 'Тестова стаття n8n | Terra Nova',
            'meta_description' => str_repeat('Перевірка керованого SEO-контенту. ', 4),
            'focus_keyword' => 'тест n8n',
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $webhook = new N8nWebhookService($database, $content, $secret);
    $result = $webhook->handle($body, $signature, $timestamp, $idempotencyKey);
    if ($result['status'] !== 200 || empty($result['payload']['content_id'])) {
        throw new RuntimeException('Inbound webhook failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    $contentId = (int) $result['payload']['content_id'];
    $article = $content->publicPost((string) $result['payload']['slug']);
    if (!$article || str_contains((string) $article['body_html'], '<script') || str_contains((string) $article['body_html'], 'javascript:') || str_contains((string) $article['body_html'], 'onerror')) {
        throw new RuntimeException('Published content or HTML sanitization assertion failed.');
    }

    $duplicate = $webhook->handle($body, $signature, $timestamp, $idempotencyKey);
    if ($duplicate['status'] !== 200) {
        throw new RuntimeException('Idempotent retry failed.');
    }
    $conflictBody = str_replace('Тестова стаття n8n', 'Інша стаття', $body);
    $conflictSignature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $conflictBody, $secret);
    if ($webhook->handle($conflictBody, $conflictSignature, $timestamp, $idempotencyKey)['status'] !== 409) {
        throw new RuntimeException('Idempotency conflict was not detected.');
    }

    $article['title'] = 'Ручне уточнення статті';
    $article['tags'] = $article['tags_json'] ?? '[]';
    $manual = $content->save($article, null, 'manual', true);
    if (empty($manual['ok'])) {
        throw new RuntimeException('Manual content update failed.');
    }
    $outbox = $database->fetchOne('
        SELECT id FROM tn_integration_outbox
        WHERE entity_type = "content" AND entity_id = :id ORDER BY id DESC LIMIT 1
    ', ['id' => $contentId]);
    $outboxId = (int) ($outbox['id'] ?? 0);
    $sent = [];
    $processor = new IntegrationOutboxProcessor($database, static function (string $outboundBody) use (&$sent): bool {
        $sent[] = json_decode($outboundBody, true);
        return true;
    });
    $stats = $processor->process(10);
    if ((int) $stats['sent'] < 1 || ($sent[0]['event'] ?? '') !== 'content.changed') {
        throw new RuntimeException('Outbound n8n delivery failed.');
    }

    echo "content and n8n integration test passed\n";
} finally {
    if ($contentId > 0) {
        $pdo->prepare('DELETE FROM tn_integration_outbox WHERE entity_type = "content" AND entity_id = :id')->execute(['id' => $contentId]);
        $pdo->prepare('DELETE FROM tn_content_items WHERE id = :id')->execute(['id' => $contentId]);
    }
    $pdo->prepare('DELETE FROM tn_webhook_deliveries WHERE idempotency_key = :key OR idempotency_key = :outbox')->execute([
        'key' => $idempotencyKey,
        'outbox' => 'outbox-' . $outboxId,
    ]);
}
