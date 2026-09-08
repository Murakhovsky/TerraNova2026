<?php

declare(strict_types=1);

use Interfaces\Web\Navigation\FrontendNavigation;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (is_dir($root . '/app/Domains/Frontend')) {
    throw new RuntimeException('Frontend is an Interface/Presentation layer and must not become a DDD Domain.');
}

$public = FrontendNavigation::public();
if (count($public) !== 5) {
    throw new RuntimeException('Public primary navigation must contain exactly five canonical sections.');
}

$workspace = FrontendNavigation::workspace('manager');
$primary = $workspace['primary'] ?? [];
if (count($primary) > 6) {
    throw new RuntimeException('Workspace primary navigation must contain no more than six sections.');
}

$expectedPrimary = ['home', 'sales', 'clients', 'properties', 'cos', 'analytics'];
$actualPrimary = array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $primary);
if ($actualPrimary !== $expectedPrimary) {
    throw new RuntimeException('Workspace primary navigation changed without an explicit interface architecture decision.');
}

$managerUtility = array_map(
    static fn (array $item): string => (string) ($item['key'] ?? ''),
    $workspace['utility'] ?? [],
);
if (in_array('users', $managerUtility, true)) {
    throw new RuntimeException('Manager navigation must not expose admin-only Users.');
}

$adminUtility = array_map(
    static fn (array $item): string => (string) ($item['key'] ?? ''),
    FrontendNavigation::workspace('admin')['utility'] ?? [],
);
if (!in_array('users', $adminUtility, true)) {
    throw new RuntimeException('Admin Workspace must expose Users in system navigation.');
}

foreach ([
    'app/Interfaces/Web/View/components/workspace_sidebar.phtml',
    'app/Interfaces/Web/View/components/workspace_topbar.phtml',
    'app/Interfaces/Web/View/components/workspace_mobile_nav.phtml',
    'frontend/core/workspace-shell.js',
    'frontend/entrypoints/terranova-interface.js',
    'frontend/styles/interface.css',
    'frontend/styles/foundation.css',
    'frontend/styles/components.css',
    'frontend/styles/patterns.css',
    'frontend/styles/workspace.css',
    'docs/architecture/frontend-interface.md',
] as $requiredPath) {
    if (!is_file($root . '/' . $requiredPath)) {
        throw new RuntimeException('WEB V0.1 interface architecture file is missing: ' . $requiredPath);
    }
}

echo "Frontend interface architecture passed: Public, Portal and Workspace boundaries are explicit.\n";
