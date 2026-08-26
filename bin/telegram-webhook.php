<?php
declare(strict_types=1);

use Longman\TelegramBot\Telegram;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require APP_PATH . '/config/config.php';

$token = (string) $config->telegram->api_key;
$username = (string) $config->telegram->bot_username;
$url = (string) $config->telegram->webhook->url;
$secret = (string) $config->telegram->secret;
if ($token === '' || $username === '' || $url === '') {
    fwrite(STDERR, "Telegram token, username and webhook URL are required.\n");
    exit(2);
}

$telegram = new Telegram($token, $username);
$options = getopt('', ['delete', 'drop-pending']);
if (isset($options['delete'])) {
    $response = $telegram->deleteWebhook(['drop_pending_updates' => isset($options['drop-pending'])]);
} else {
    $data = [
        'allowed_updates' => ['message', 'callback_query'],
        'drop_pending_updates' => isset($options['drop-pending']),
    ];
    if ($secret !== '') {
        $data['secret_token'] = $secret;
    }
    $response = $telegram->setWebhook($url, $data);
}

echo ($response->isOk() ? 'ok: ' : 'error: ') . $response->getDescription() . PHP_EOL;
