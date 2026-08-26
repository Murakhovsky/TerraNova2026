<?php
declare(strict_types=1);

use Common\Services\TelegramAutomationService;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Modules\TgAdmin\Services\TelegramAutomationProcessor;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';

$config = $di->getShared('config');
$token = (string) $config->telegram->api_key;
$username = (string) $config->telegram->bot_username;
if ($token === '' || $username === '') {
    fwrite(STDERR, "Set TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_NAME in .env.\n");
    exit(2);
}

$options = getopt('', ['schedule', 'digest', 'limit::']);
$limit = isset($options['limit']) ? max(1, min(100, (int) $options['limit'])) : 25;
$automation = $di->getShared('telegramAutomationService');
assert($automation instanceof TelegramAutomationService);

$scheduled = isset($options['schedule']) ? $automation->scheduleDueReminders() : 0;
$digests = isset($options['digest']) ? $automation->queueDailyDigest() : 0;
$telegram = new Telegram($token, $username);
$baseUrl = rtrim((string) $config->application->publicUrl, '/');

$processor = new TelegramAutomationProcessor(
    $di->getShared('databaseService'),
    static function (int $chatId, array $payload) use ($baseUrl): bool|string {
        $data = [
            'chat_id' => $chatId,
            'text' => (string) ($payload['text'] ?? ''),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $rows = [];
        foreach ((array) ($payload['buttons'] ?? []) as $button) {
            $label = trim((string) ($button[0] ?? ''));
            $target = trim((string) ($button[1] ?? ''));
            if ($label === '' || $target === '' || str_ends_with($target, '/0')) {
                continue;
            }
            $url = preg_match('~^https?://~i', $target) ? $target : $baseUrl . '/' . ltrim($target, '/');
            $rows[] = [['text' => $label, 'url' => $url]];
        }
        if ($rows) {
            $data['reply_markup'] = new InlineKeyboard(...$rows);
        }

        $response = Request::sendMessage($data);
        return $response->isOk() ? true : (string) $response->getDescription();
    }
);

$result = $processor->process($limit);
$result['scheduled'] = $scheduled;
$result['digests'] = $digests;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
