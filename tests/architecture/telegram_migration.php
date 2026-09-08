<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (is_dir($root . '/app/modules')) {
    throw new RuntimeException('Telegram migration is incomplete: app/modules still exists.');
}

$configSource = (string) file_get_contents($root . '/app/config/config.php');
if (str_contains($configSource, '/modules/')) {
    throw new RuntimeException('Telegram command configuration still points to app/modules.');
}

// Telegram source is retained for possible reactivation, but it must remain outside
// the active shared Web/CLI runtime while the legacy runtime is disabled.
$sharedServices = (string) file_get_contents($root . '/app/config/services.php');
foreach (['telegramAutomationService', 'telegramAccessPolicy', 'TelegramAutomationService', 'TelegramAccessPolicy'] as $telegramDependency) {
    if (str_contains($sharedServices, $telegramDependency)) {
        throw new RuntimeException('Shared DI still depends on legacy Telegram: ' . $telegramDependency);
    }
}

$telegramServices = (string) file_get_contents($root . '/app/config/services_tg.php');
foreach (['telegramAutomationService', 'telegramAccessPolicy'] as $telegramService) {
    if (!str_contains($telegramServices, $telegramService)) {
        throw new RuntimeException('Telegram-only DI lost retained legacy service: ' . $telegramService);
    }
}

$webhook = (string) file_get_contents($root . '/public/tgAdmin_webhook.php');
if (!str_contains($webhook, 'http_response_code(410)') || str_contains($webhook, 'bootstrap_tg.php')) {
    throw new RuntimeException('Legacy Telegram public webhook is not safely disabled.');
}

$legacyBootstrap = $root . '/app/bootstrap_tg.php';
if (!is_file($legacyBootstrap)) {
    throw new RuntimeException('Retained Telegram bootstrap disappeared; reactivation path was lost.');
}

$roots = [
    $root . '/app/Interfaces/Telegram',
    $root . '/app/Infrastructure/Integration/Telegram',
    $root . '/app/Domains/Identity/Infrastructure/Persistence/Phalcon/Telegram',
    $root . '/app/Domains/Property/Infrastructure/Persistence/Phalcon/Telegram',
    $root . '/app/Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram',
];

$phpFiles = 0;
foreach ($roots as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $phpFiles++;
        $source = (string) file_get_contents($file->getPathname());
        foreach (['TelegramModels\\', 'Modules\\TgAdmin', 'OpenAIPlugin\\', 'Parser\\Advert\\'] as $forbiddenNamespace) {
            if (str_contains($source, $forbiddenNamespace)) {
                throw new RuntimeException(
                    'Retained Telegram source still references removed legacy namespace '
                    . $forbiddenNamespace . ': ' . $file->getPathname()
                );
            }
        }
    }
}

if ($phpFiles === 0) {
    throw new RuntimeException('Telegram legacy source was unexpectedly removed entirely.');
}

echo "Telegram architecture passed: legacy source retained, shared runtime isolated, public webhook disabled.\n";
