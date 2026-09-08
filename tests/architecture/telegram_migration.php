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

// Telegram is retained as legacy source, but must stay outside the shared Web/CLI runtime.
$sharedServices = (string) file_get_contents($root . '/app/config/services.php');
foreach (['telegramAutomationService', 'telegramAccessPolicy', 'TelegramAutomationService', 'TelegramAccessPolicy'] as $telegramDependency) {
    if (str_contains($sharedServices, $telegramDependency)) {
        throw new RuntimeException('Shared DI still depends on legacy Telegram: ' . $telegramDependency);
    }
}

$telegramServices = (string) file_get_contents($root . '/app/config/services_tg.php');
foreach (['telegramAutomationService', 'telegramAccessPolicy'] as $telegramService) {
    if (!str_contains($telegramServices, $telegramService)) {
        throw new RuntimeException('Telegram-only DI lost legacy service: ' . $telegramService);
    }
}

$webhook = (string) file_get_contents($root . '/public/tgAdmin_webhook.php');
if (!str_contains($webhook, 'http_response_code(410)') || str_contains($webhook, 'bootstrap_tg.php')) {
    throw new RuntimeException('Legacy Telegram public webhook is not safely disabled.');
}

$roots = [
    $root . '/app/Interfaces/Telegram/Command',
    $root . '/app/Interfaces/Telegram/Controller',
    $root . '/app/Interfaces/Telegram/Rendering',
    $root . '/app/Interfaces/Telegram/Presentation',
    $root . '/app/Infrastructure/Integration/Telegram/ActiveRecord',
    $root . '/app/Domains/Identity/Infrastructure/Persistence/Phalcon/Telegram',
    $root . '/app/Domains/Property/Infrastructure/Persistence/Phalcon/Telegram',
    $root . '/app/Domains/Sales/Infrastructure/Persistence/Phalcon/Telegram',
];

$loaded = 0;
foreach ($roots as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        foreach (['TelegramModels\\', 'Modules\\TgAdmin', 'OpenAIPlugin\\', 'Parser\\Advert\\'] as $forbiddenNamespace) {
            if (str_contains($source, $forbiddenNamespace)) {
                throw new RuntimeException(
                    'Migrated Telegram source still references legacy namespace '
                    . $forbiddenNamespace . ': ' . $file->getPathname()
                );
            }
        }
        if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
            || !preg_match('/^(?:abstract\s+|final\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)|^interface\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $type)
        ) {
            continue;
        }

        require_once $file->getPathname();
        $shortName = ($type[1] ?? '') !== '' ? $type[1] : ($type[2] ?? '');
        $fqcn = trim($namespace[1]) . '\\' . $shortName;
        if (!class_exists($fqcn, false) && !interface_exists($fqcn, false)) {
            throw new RuntimeException('Migrated Telegram type failed to load: ' . $fqcn);
        }
        $loaded++;
    }
}

foreach ([
    Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Person\AppUsers::class,
    Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Company\Employees::class,
    Domains\Property\Infrastructure\Persistence\Phalcon\Telegram\Estate\Objects::class,
    Domains\Sales\Infrastructure\Persistence\Phalcon\Telegram\Request\Requests::class,
    Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Preference\Lists::class,
    Interfaces\Telegram\Rendering\ObjectCardBuilder::class,
    Interfaces\Telegram\Rendering\RequestCardBuilder::class,
] as $requiredType) {
    if (!class_exists($requiredType)) {
        throw new RuntimeException('Required canonical Telegram type was not loaded: ' . $requiredType);
    }
}

require_once $root . '/app/Interfaces/Telegram/Language/language_uk.php';
$menuButtons = Interfaces\Telegram\Rendering\Buttons::getMainMenu([
    'xp' => 0,
    'level' => 0,
    'msg' => 0,
]);
if (count($menuButtons) !== 4) {
    throw new RuntimeException('Migrated Telegram main menu has an invalid navigation shape.');
}

$telegram = new Longman\TelegramBot\Telegram('123456:test-token', 'migration_test_bot');
$telegram->setCommandsPaths([
    $root . '/app/Interfaces/Telegram/Command/SystemCommands',
    $root . '/app/Interfaces/Telegram/Command/UserCommands',
]);
$commands = $telegram->getCommandsList();
foreach ([
    'call', 'company', 'favourite', 'get_photos', 'groups_message', 'hidekb', 'inlinekeyboard',
    'menu', 'new_object', 'profile', 'request', 'search_adverts', 'set_cold_phones', 'set_reminder',
    'showing', 'start', 'tasks', 'unban', 'weather', 'callbackquery', 'choseninlineresult',
    'genericmessage', 'inlinequery',
] as $requiredCommand) {
    if (!isset($commands[$requiredCommand])) {
        throw new RuntimeException(
            'Migrated Telegram command was not discovered: ' . $requiredCommand
            . '; discovered: ' . implode(', ', array_keys($commands))
        );
    }
}

echo "Telegram migration passed: runtime isolated/disabled, {$loaded} legacy types loadable and " . count($commands)
    . " commands discoverable.\n";
