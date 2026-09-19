<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'app/bootstrap_tg.php',
    'app/config/services_tg.php',
    'app/Interfaces/Telegram',
    'app/Infrastructure/Integration/Telegram/ActiveRecord',
    'app/Domains/Identity/Infrastructure/Persistence/Phalcon/Telegram',
    'app/Domains/Property/Infrastructure/Persistence/Phalcon/Telegram',
    'app/Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram',
    'app/Infrastructure/Framework/PhalconEventService.php',
    'app/Infrastructure/Security/TelegramAccessPolicy.php',
    'bin/telegram-webhook.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Telegram/Phalcon runtime restored: ' . $path);
}

$webhook = (string) file_get_contents($root . '/public/tgAdmin_webhook.php');
$assert(str_contains($webhook, 'http_response_code(410)'), 'Stale public Telegram webhook tombstone must remain HTTP 410.');
$assert(!str_contains($webhook, 'bootstrap_tg.php'), 'Telegram tombstone must never boot a framework runtime.');

$automation = (string) file_get_contents($root . '/app/Infrastructure/Integration/Telegram/TelegramAutomationService.php');
$processor = (string) file_get_contents($root . '/app/Infrastructure/Integration/Telegram/TelegramAutomationProcessor.php');
foreach ([$automation, $processor] as $source) {
    $assert(!str_contains($source, 'Phalcon\\'), 'Outbound Telegram automation must remain framework-neutral.');
}

$config = (string) file_get_contents($root . '/app/config/config.php');
$assert(str_contains($config, 'TELEGRAM_BOT_TOKEN'), 'Outbound Telegram token configuration is missing.');
$assert(str_contains($config, 'TELEGRAM_BOT_NAME'), 'Outbound Telegram bot-name configuration is missing.');
foreach (['TELEGRAM_WEBHOOK_URL', 'TELEGRAM_WEBHOOK_SECRET', "Interfaces/Telegram/Command"] as $legacy) {
    $assert(!str_contains($config, $legacy), 'Retired inbound Telegram configuration restored: ' . $legacy);
}

$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/CoreWebRoutes.php');
$assert(!str_contains($routes, 'telegramConnect'), 'Cabinet still exposes retired Telegram connect route.');
$assert(!str_contains($routes, 'telegramDisconnect'), 'Cabinet still exposes retired Telegram disconnect route.');

echo "Telegram architecture passed: Phalcon inbound bot retired, outbound notification channel retained.\n";
