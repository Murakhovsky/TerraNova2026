<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$required = [
    'app/Interfaces/Web/Controller/ClientCaseController.php',
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
    'frontend/features/clients/workspace.js',
    'docs/architecture/web-v0.6.md',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing WEB V0.6 Clients Workspace artifact: ' . $path);
    }
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/ClientCaseController.php');
foreach ([
    "prepareWorkspace('Клієнтські кейси', 'cases')",
    "prepareWorkspace('Вхідні заявки', 'inbox')",
    "prepareWorkspace('Картка кейсу', 'cases')",
    "workspaceSection = 'clients'",
    "pageAssetEntries = ['clients-workspace']",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('ClientCaseController is missing WEB V0.6 workspace contract: ' . $needle);
    }
}
if (str_contains($controller, 'Domains\\Clients')) {
    throw new RuntimeException('WEB V0.6 must not invent a Clients Domain for a presentation migration.');
}

$layout = (string) file_get_contents($root . '/app/Interfaces/Web/View/index.phtml');
if (!str_contains($layout, "'layoutOwned' => true")) {
    throw new RuntimeException('Global Web layout must mark the shared Workspace shell as layout-owned.');
}

$managerHeader = (string) file_get_contents($root . '/app/Interfaces/Web/View/shared/manager_header.phtml');
foreach (['$layoutOwned', '$workspaceSection', 'if (!$layoutOwned && $workspaceSection !== \'\')'] as $needle) {
    if (!str_contains($managerHeader, $needle)) {
        throw new RuntimeException('Shared manager header is missing the WEB V0.6 duplicate-shell guard: ' . $needle);
    }
}

foreach (['inbox.phtml', 'index.phtml', 'show.phtml'] as $viewFile) {
    $view = (string) file_get_contents($root . '/app/Interfaces/Web/View/client_case/' . $viewFile);
    if (!str_contains($view, "partial('shared/manager_header'")) {
        throw new RuntimeException('Legacy Client Case view changed unexpectedly; the V0.6 compatibility guard must cover its historical shell call: ' . $viewFile);
    }
    if (str_contains($view, '/assets/js/') || str_contains($view, '/assets/css/')) {
        throw new RuntimeException('Clients Workspace view bypasses Vite: ' . $viewFile);
    }
}

$entrypoint = (string) file_get_contents($root . '/frontend/entrypoints/clients-workspace.js');
foreach (["../features/clients/workspace.css", "../features/clients/workspace.js"] as $needle) {
    if (!str_contains($entrypoint, $needle)) {
        throw new RuntimeException('Clients Workspace Vite entrypoint is incomplete: ' . $needle);
    }
}

$clientJs = (string) file_get_contents($root . '/frontend/features/clients/workspace.js');
foreach (['data-client-workspace', "addEventListener('submit'", 'aria-busy'] as $needle) {
    if (!str_contains($clientJs, $needle)) {
        throw new RuntimeException('Clients Workspace progressive enhancement is incomplete: ' . $needle);
    }
}

$clientCss = (string) file_get_contents($root . '/frontend/features/clients/workspace.css');
foreach (['.tn-client-workspace', '.tn-case-funnel', '@media (max-width: 650px)'] as $needle) {
    if (!str_contains($clientCss, $needle)) {
        throw new RuntimeException('Clients Workspace responsive styling is incomplete: ' . $needle);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (!str_contains($vite, "'clients-workspace': resolve(import.meta.dirname, 'frontend/entrypoints/clients-workspace.js')")) {
    throw new RuntimeException('Vite does not expose the WEB V0.6 Clients Workspace entrypoint.');
}

$assetTest = (string) file_get_contents($root . '/tests/architecture/frontend_assets.php');
if (!str_contains($assetTest, "'clients-workspace'")) {
    throw new RuntimeException('Frontend asset architecture does not validate the Clients Workspace bundle.');
}

$salesNavigation = (string) file_get_contents($root . '/app/Interfaces/Web/Navigation/SalesNavigationContributor.php');
foreach (['client-case/inbox', "'key' => 'clients'", "'key' => 'cases'"] as $needle) {
    if (!str_contains($salesNavigation, $needle)) {
        throw new RuntimeException('Sales module must continue to own Clients Workspace navigation: ' . $needle);
    }
}

echo "WEB V0.6 Clients Workspace architecture passed: Inbox, Cases and Case Workspace use the shared layout-owned shell and dedicated Vite bundle.\n";
