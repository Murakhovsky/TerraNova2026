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
    'app/Interfaces/Web/View/components/ui/page_header.phtml',
    'app/Interfaces/Web/View/components/ui/kpi_card.phtml',
    'app/Interfaces/Web/View/components/ui/state.phtml',
    'app/Interfaces/Web/View/components/ui/status_badge.phtml',
    'app/Interfaces/Web/View/components/ui/tabs.phtml',
    'frontend/components/interactive.js',
    'frontend/core/workspace-shell.js',
    'frontend/entrypoints/terranova-interface.js',
    'frontend/entrypoints/cos-control-center.js',
    'frontend/entrypoints/diagnostics-methodology-studio.js',
    'frontend/features/cos/control-center.css',
    'frontend/features/diagnostics/methodology-studio.css',
    'frontend/features/diagnostics/methodology-studio.js',
    'frontend/layouts/surfaces.css',
    'frontend/styles/interface.css',
    'frontend/styles/foundation.css',
    'frontend/styles/components.css',
    'frontend/styles/patterns.css',
    'frontend/styles/workspace.css',
    'docs/architecture/frontend-interface.md',
    'docs/architecture/web-v0.2.md',
] as $requiredPath) {
    if (!is_file($root . '/' . $requiredPath)) {
        throw new RuntimeException('Frontend interface architecture file is missing: ' . $requiredPath);
    }
}

$cosController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/CosController.php');
if (!str_contains($cosController, "workspaceSection = 'cos'") || !str_contains($cosController, "['cos-control-center']")) {
    throw new RuntimeException('COS Control Center must opt into the Workspace shell and its feature bundle.');
}

$diagnosticController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/MethodologyStudioController.php');
if (!str_contains($diagnosticController, "['diagnostics-methodology-studio']")) {
    throw new RuntimeException('Methodology Studio must load through a Vite feature entrypoint.');
}

$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View'));
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') continue;
    $source = (string) file_get_contents($view->getPathname());
    if (str_contains($source, '/assets/js/') || str_contains($source, '/assets/css/')) {
        throw new RuntimeException('PHTML must not bypass Vite with direct /assets JS/CSS references: ' . $view->getPathname());
    }
}

echo "Frontend interface architecture passed: WEB V0.2 foundation, feature bundles and Workspace boundaries are explicit.\n";
