<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/symfony/src/Web/Experience/Shell/ShellConnectionState.php';
require_once $root . '/symfony/src/Web/Experience/Shell/ShellBreadcrumb.php';
require_once $root . '/symfony/src/Web/Experience/Shell/ShellCommandItem.php';
require_once $root . '/symfony/src/Web/Experience/Shell/ShellNavigationItem.php';
require_once $root . '/symfony/src/Web/Experience/Shell/ShellViewModel.php';
require_once $root . '/symfony/src/Web/Experience/Shell/WorkspaceShellNavigationAdapter.php';

use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\WorkspaceShellNavigationAdapter;

foreach ([
    ShellConnectionState::Live->value => 'live',
    ShellConnectionState::Reconnecting->value => 'reconnecting',
    ShellConnectionState::Offline->value => 'offline',
    ShellConnectionState::Stale->value => 'stale',
] as $actual => $expected) {
    if ($actual !== $expected) {
        throw new RuntimeException('Workspace Shell connection state contract drifted.');
    }
}

$adapter = new WorkspaceShellNavigationAdapter();
$adapted = $adapter->adapt([
    'primary' => [
        [
            'key' => 'sales',
            'label' => 'Sales',
            'path' => 'sales/dashboard',
            'glyph' => 'SL',
            'children' => [
                ['key' => 'pipeline', 'label' => 'Pipeline', 'path' => 'sales/pipeline'],
            ],
        ],
    ],
    'utility' => [
        ['key' => 'cabinet', 'label' => 'Cabinet', 'path' => 'cabinet', 'glyph' => 'ME'],
    ],
], 'sales', 'pipeline');

if (($adapted['primary'][0]->path ?? null) !== '/sales/dashboard') {
    throw new RuntimeException('Workspace Shell navigation adapter did not normalize paths.');
}

if (($adapted['primary'][0]->active ?? false) !== true) {
    throw new RuntimeException('Workspace Shell navigation adapter lost active section state.');
}

if (($adapted['primary'][0]->children[0]->active ?? false) !== true) {
    throw new RuntimeException('Workspace Shell navigation adapter lost active child state.');
}

$commandIds = array_map(static fn($command): string => $command->id, $adapted['commands']);
foreach (['navigate.sales', 'navigate.pipeline', 'navigate.cabinet'] as $commandId) {
    if (!in_array($commandId, $commandIds, true)) {
        throw new RuntimeException('Workspace Shell command projection is missing: ' . $commandId);
    }
}

$shellDir = $root . '/symfony/src/Web/Experience/Shell';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shellDir));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());

    foreach (['Domains\\', 'Doctrine\\', 'HttpClientInterface', '/api/v1/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Workspace Shell contract %s contains forbidden business/data dependency: %s',
                $file->getFilename(),
                $forbidden,
            ));
        }
    }
}

$controller = (string) file_get_contents($root . '/symfony/assets/controllers/workspace_shell_controller.js');
foreach (['localStorage', 'sessionStorage', 'fetch(', 'axios', '/api/'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Workspace Shell browser behavior contains forbidden persistence/data access: ' . $forbidden);
    }
}

foreach ([
    'toggleSidebar',
    'openPalette',
    'closePalette',
    'filterPalette',
    'Cmd/Ctrl+K',
] as $contract) {
    if ($contract === 'Cmd/Ctrl+K') {
        if (!str_contains($controller, "event.metaKey || event.ctrlKey")) {
            throw new RuntimeException('Workspace Shell command keyboard shortcut contract is missing.');
        }
        continue;
    }

    if (!str_contains($controller, $contract)) {
        throw new RuntimeException('Workspace Shell browser contract is missing: ' . $contract);
    }
}

$shellCss = (string) file_get_contents($root . '/symfony/assets/styles/shell.css');
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $shellCss) === 1) {
    throw new RuntimeException('Workspace Shell CSS must use semantic tokens instead of raw hex colors.');
}

if (str_contains($shellCss, '--tn-') || str_contains($shellCss, '.tn-')) {
    throw new RuntimeException('Workspace Shell CSS must not restore legacy TN selectors or tokens.');
}

foreach ([
    '.cos-shell__sidebar',
    '.cos-shell__topbar',
    '.cos-shell__mobile-nav',
    '.cos-command__panel',
    '@media (max-width: 760px)',
] as $selector) {
    if (!str_contains($shellCss, $selector)) {
        throw new RuntimeException('Workspace Shell responsive style contract is missing: ' . $selector);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
foreach ([
    'shell.primaryNavigation',
    'shell.utilityNavigation',
    'shell.breadcrumbs',
    'shell.commands',
    'shell.notificationCount',
    'shell.activityCount',
    'shell.connectionState.value',
    'shell.aiAvailable',
    'data-controller="workspace-shell"',
    'Mobile Workspace navigation',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Workspace Shell template contract is missing: ' . $marker);
    }
}

foreach (['NavigationBuilder', 'Domains\\', 'Doctrine\\', '/api/v1/'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('Workspace Shell Twig leaked implementation/business dependency: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
if (!str_contains($routes, 'cos_web_workspace_shell_preview:') || !str_contains($routes, 'path: /dev/shell')) {
    throw new RuntimeException('Canonical /dev/shell preview route is missing.');
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
if (!str_contains($security, "path: '^/dev(?:/|$)'") || !str_contains($security, 'roles: ROLE_MANAGER')) {
    throw new RuntimeException('Workspace Shell preview must remain manager-only.');
}

echo "Wave 12.4 Workspace Shell foundation passed.\n";
