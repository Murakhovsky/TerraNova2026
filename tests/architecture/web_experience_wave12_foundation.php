<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'app/Kernel/Module/ModuleExtensionPoint.php',
    'symfony/src/Web/Experience/Model/EntityRef.php',
    'symfony/src/Web/Experience/Action/UIActionIntent.php',
    'symfony/src/Web/Experience/Action/UIAction.php',
    'docs/11-decisions/ADR-0009-web-experience-platform.md',
];

foreach ($files as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12 foundation artifact is missing: ' . $relative);
    }
}

require_once $root . '/app/Kernel/Module/ModuleExtensionPoint.php';
require_once $root . '/symfony/src/Web/Experience/Model/EntityRef.php';
require_once $root . '/symfony/src/Web/Experience/Action/UIActionIntent.php';
require_once $root . '/symfony/src/Web/Experience/Action/UIAction.php';

$expectedExtensionPoints = [
    'WEB_NAVIGATION' => 'web.navigation',
    'WEB_SEARCH' => 'web.search',
    'WEB_COMMANDS' => 'web.commands',
    'WEB_WORKSPACE' => 'web.workspace',
    'WEB_WORKSPACE_EXTENSIONS' => 'web.workspace.extensions',
    'WEB_DASHBOARD_WIDGETS' => 'web.dashboard_widgets',
    'WEB_ENTITY_LINKS' => 'web.entity_links',
    'WEB_NOTIFICATIONS' => 'web.notifications',
    'WEB_ACTIVITY' => 'web.activity',
    'WEB_ACTIONS' => 'web.actions',
];

$reflection = new ReflectionClass(Kernel\Module\ModuleExtensionPoint::class);
foreach ($expectedExtensionPoints as $constant => $value) {
    if (!$reflection->hasConstant($constant) || $reflection->getConstant($constant) !== $value) {
        throw new RuntimeException('Missing canonical Wave 12 extension point: ' . $constant);
    }
}

$ref = App\Web\Experience\Model\EntityRef::fromKey('client:123');
if ($ref->type !== 'client' || $ref->id !== '123' || $ref->key() !== 'client:123') {
    throw new RuntimeException('EntityRef canonical key contract failed.');
}

$safe = new App\Web\Experience\Action\UIAction(
    id: 'sales.lead.assign',
    label: 'Assign lead',
    intent: App\Web\Experience\Action\UIActionIntent::Execute,
    command: 'sales.lead.assign',
    placements: ['workspace', 'command_palette'],
);
if ($safe->isDangerous()) {
    throw new RuntimeException('Non-destructive UIAction must not be dangerous.');
}

$danger = new App\Web\Experience\Action\UIAction(
    id: 'sales.lead.delete',
    label: 'Delete lead',
    intent: App\Web\Experience\Action\UIActionIntent::Delete,
    confirmation: 'Confirm lead deletion.',
    command: 'sales.lead.delete',
    dangerLevel: 2,
);
if (!$danger->isDangerous()) {
    throw new RuntimeException('Destructive UIAction must be dangerous.');
}

try {
    new App\Web\Experience\Action\UIAction(
        id: 'sales.lead.delete',
        label: 'Delete lead',
        intent: App\Web\Experience\Action\UIActionIntent::Delete,
    );
    throw new RuntimeException('Dangerous UIAction without confirmation was accepted.');
} catch (InvalidArgumentException) {
}

try {
    new App\Web\Experience\Action\UIAction(
        id: 'delete',
        label: 'Delete lead',
        intent: App\Web\Experience\Action\UIActionIntent::Delete,
        confirmation: 'Confirm lead deletion.',
    );
    throw new RuntimeException('Unqualified UIAction id was accepted.');
} catch (InvalidArgumentException) {
}

try {
    new App\Web\Experience\Action\UIAction(
        id: 'sales.lead.assign',
        label: 'Assign lead',
        intent: App\Web\Experience\Action\UIActionIntent::Execute,
    );
    throw new RuntimeException('Executable UIAction without command was accepted.');
} catch (InvalidArgumentException) {
}

$experienceRoot = $root . '/symfony/src/Web/Experience';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($experienceRoot, FilesystemIterator::SKIP_DOTS),
);
$forbidden = [
    'use Domains\\',
    'use Doctrine\\',
    'HttpClientInterface',
    '/api/v1/',
];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException(
                'Wave 12 Experience primitive crossed an architecture boundary: '
                . substr($file->getPathname(), strlen($root) + 1)
                . ' -> '
                . $needle,
            );
        }
    }
}

echo "Wave 12 Web Experience foundation passed.\n";
