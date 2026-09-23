<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Sales/SalesAdminDashboardController.php',
    'symfony/src/Web/Sales/SalesAdminDashboardPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesAdminDashboardViewModel.php',
    'symfony/templates/experience/sales/admin/dashboard.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-009 admin foundation artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/sales/admin.phtml')) {
    throw new RuntimeException('VR-009 canonical Sales Admin dashboard must not retain legacy PHTML ownership.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
if (!str_contains($routes, 'SalesAdminDashboardController::index')) {
    throw new RuntimeException('Sales Admin dashboard route has not moved to the canonical controller.');
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Sales/Admin/SalesAdminQueryHandler.php');
foreach ([
    "'dashboard'",
    "'integration.list'",
    "'integration.catalog'",
    "'integration.routing_options'",
    "'health.dashboard'",
    'SalesIntegrationAdministrationInterface',
    'SalesAdministrationReadModelInterface',
    'SalesWorkspaceOperationalReadModelInterface',
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('Sales Admin canonical read boundary is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesAdminDashboardController.php');
foreach ([
    'SalesAdminQuery',
    "new SalesAdminQuery(\$tenant->organizationId(), 'dashboard')",
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    "'Toolbar'",
    "'KpiStrip'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Sales Admin dashboard controller contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/admin/dashboard.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosMetric',
    '<twig:CosActionBar',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Sales Admin control surface composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('Sales Admin dashboard restored legacy/local presentation: ' . $forbidden);
    }
}

echo "Wave 13 VR-009 Sales Admin foundation passed.\n";
