<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Workspace/ExecutiveDashboardController.php',
    'symfony/templates/experience/admin/dashboard.html.twig',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-001 production artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/admin/index.phtml')) {
    throw new RuntimeException('VR-001 must delete the legacy Company Home PHTML view.');
}
if (is_file($root . '/frontend/entrypoints/company-home.js')
    || is_file($root . '/frontend/features/home/company-home.css')) {
    throw new RuntimeException('VR-001 must delete the page-specific Company Home browser asset.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /admin', 'ExecutiveDashboardController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-001 route cutover is incomplete: ' . $marker);
    }
}
if (str_contains($routes, 'CoreWorkspacePageController::home')) {
    throw new RuntimeException('Legacy /admin PHTML controller ownership remains reachable.');
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Workspace/ExecutiveDashboardController.php');
foreach ([
    'GetExecutiveDashboardQuery',
    'PageArchetype::ExecutiveDashboard',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    "'permission_denied'",
    "'error'",
    "experience/admin/dashboard.html.twig",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-001 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Repository', '/api/', 'Doctrine\\'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-001 controller contains forbidden delivery/data dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/admin/dashboard.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosMetric',
    'class="cos-kpi-strip"',
    '<twig:CosNextAction',
    '<twig:CosEntityListItem',
    '<twig:CosEmptyState',
    '<twig:CosAlert',
    'data-cos-archetype',
    'data-cos-page-state',
    'dashboard.propertySummary.published',
    'dashboard.decisions',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-001 Executive Dashboard composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-001 Twig restored legacy/local presentation: ' . $forbidden);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, "'company-home'")) {
    throw new RuntimeException('VR-001 retains a page-specific Company Home Vite entrypoint.');
}

$core = (string) file_get_contents($root . '/symfony/src/Web/Workspace/CoreWorkspacePageController.php');
foreach (['CompanyHomeService', 'public function home('] as $forbidden) {
    if (str_contains($core, $forbidden)) {
        throw new RuntimeException('VR-001 retained obsolete CoreWorkspace Company Home ownership: ' . $forbidden);
    }
}

$shell = (string) file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
if (str_contains($shell, '<h1 class="cos-shell__title">')) {
    throw new RuntimeException('Application Shell must not compete with the page archetype for the document h1.');
}

echo "Wave 13 VR-001 /admin Executive Dashboard passed.\n";
