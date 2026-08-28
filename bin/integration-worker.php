<?php
declare(strict_types=1);

use Infrastructure\Persistence\MySql\Database\Connection\DatabaseService;
use Domains\Content\Application\Service\ContentService;
use Infrastructure\Integration\N8n\IntegrationOutboxProcessor;
use Infrastructure\Persistence\MySql\Content\MysqlContentRepository;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
$config = $di->getShared('config')->integrations->n8n;
$database = $di->getShared('databaseService');
assert($database instanceof DatabaseService);
$content = new ContentService(new MysqlContentRepository($database));
$options = getopt('', ['schedule-content', 'limit::']);
$scheduled = isset($options['schedule-content']) ? $content->publishScheduled() : 0;
$limit = isset($options['limit']) ? max(1, min(100, (int) $options['limit'])) : 25;
$url = trim((string) $config->outbound_url);
$secret = (string) $config->outbound_secret;

if ($url === '' || $secret === '') {
    echo json_encode(['disabled' => true, 'scheduled_content' => $scheduled, 'message' => 'N8N_OUTBOUND_URL or N8N_OUTBOUND_SECRET is not configured.'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$processor = new IntegrationOutboxProcessor(
    $database,
    static function (string $body, array $item) use ($url, $secret): bool|string {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-TN-Timestamp: ' . $timestamp,
                'X-TN-Signature: sha256=' . $signature,
                'X-TN-Idempotency-Key: tn-outbox-' . $item['id'],
            ],
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        unset($curl);
        if (!is_string($response)) {
            return $error ?: 'n8n connection failed.';
        }
        return $httpCode >= 200 && $httpCode < 300
            ? true
            : 'n8n HTTP ' . $httpCode . ': ' . mb_substr(strip_tags($response), 0, 500);
    }
);

$result = $processor->process($limit);
$result['scheduled_content'] = $scheduled;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
