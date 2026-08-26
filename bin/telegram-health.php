<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require APP_PATH . '/config/config.php';

$token = (string) $config->telegram->api_key;
$username = (string) $config->telegram->bot_username;
if ($token === '' || $username === '') {
    fwrite(STDERR, "Telegram credentials are not configured.\n");
    exit(2);
}

$curl = curl_init('https://api.telegram.org/bot' . $token . '/getWebhookInfo');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$body = curl_exec($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
unset($curl);
if (!is_string($body) || $body === '') {
    fwrite(STDERR, 'Telegram API is unavailable: ' . ($error ?: 'empty response') . PHP_EOL);
    exit(1);
}
$response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$result = (array) ($response['result'] ?? []);
echo json_encode([
    'http_code' => $httpCode,
    'ok' => (bool) ($response['ok'] ?? false),
    'description' => $response['description'] ?? null,
    'url' => $result['url'] ?? null,
    'pending_update_count' => $result['pending_update_count'] ?? null,
    'last_error_date' => $result['last_error_date'] ?? null,
    'last_error_message' => $result['last_error_message'] ?? null,
    'max_connections' => $result['max_connections'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
