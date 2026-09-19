<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(!is_file($root . '/bin/telegram-worker.php'), 'Retired Phalcon Telegram worker entrypoint was restored.');

$command = $read('symfony/src/Command/TelegramNotificationsProcessCommand.php');
$worker = $read('symfony/src/Infrastructure/Telegram/TelegramNotificationWorker.php');
$sender = $read('symfony/src/Infrastructure/Telegram/TelegramNotificationSender.php');

$assert(
    str_contains($command, "name: 'cos:telegram:notifications:process'"),
    'Canonical Symfony Telegram worker command is missing.',
);
foreach (["addOption('schedule'", "addOption('digest'", "addOption('limit'"] as $option) {
    $assert(str_contains($command, $option), 'Telegram worker command lost option: ' . $option);
}

foreach ([$command, $worker, $sender] as $source) {
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Telegram worker depends on Phalcon.');
    $assert(!str_contains($source, 'Longman\\TelegramBot'), 'Canonical Telegram worker depends on retired Longman runtime.');
}

foreach ([
    'TelegramAutomationProcessor',
    'scheduleDueReminders()',
    'queueDailyDigest()',
] as $needle) {
    $assert(str_contains($worker, $needle), 'Canonical worker lost legacy-compatible behavior: ' . $needle);
}

foreach ([
    'curl_init(',
    'api.telegram.org/bot',
    '/sendMessage',
    "'inline_keyboard'",
] as $needle) {
    $assert(str_contains($sender, $needle), 'Telegram Bot API sender is missing: ' . $needle);
}

$services = $read('symfony/config/services.yaml');
foreach ([
    'Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection:',
    "\$config: '@legacy_cos.pdo'",
    'Infrastructure\\Integration\\Telegram\\TelegramAutomationService:',
    'App\\Infrastructure\\Telegram\\TelegramNotificationSender:',
    'App\\Infrastructure\\Telegram\\TelegramNotificationWorker:',
    '%env(TELEGRAM_BOT_TOKEN)%',
    '%env(TELEGRAM_BOT_NAME)%',
    '%env(APP_URL)%',
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony Telegram worker composition is missing: ' . $needle);
}

$compose = $read('docker-compose.symfony.yml');
foreach (['TELEGRAM_BOT_TOKEN:', 'TELEGRAM_BOT_NAME:', 'APP_URL:'] as $needle) {
    $assert(str_contains($compose, $needle), 'Symfony runtime environment is missing: ' . $needle);
}

$docs = $read('docs/telegram-automation.md');
$assert(
    str_contains($docs, 'cos:telegram:notifications:process'),
    'Telegram operations documentation does not reference the canonical Symfony worker.',
);
$assert(
    !str_contains($docs, 'bin/telegram-worker.php'),
    'Telegram operations documentation still references the retired Phalcon worker.',
);

echo "Telegram outbound worker retirement boundary OK\n";
