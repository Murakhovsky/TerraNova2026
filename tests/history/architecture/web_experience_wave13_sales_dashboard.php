<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Sales/SalesDashboardController.php',
    'symfony/src/Web/Sales/SalesDashboardPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesDashboardViewModel.php',
    'symfony/templates/experience/sales/dashboard.html.twig',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-002 artifact is missing: ' . $relative);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/dashboard', 'SalesDashboardController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-002 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDashboardController.php');
foreach ([
    'GetSalesDashboardQuery',
    'PageArchetype::DomainDashboard',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    'SalesDashboardPresenter',
    "experience/sales/dashboard.html.twig",
    "'error'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-002 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-002 controller leaked forbidden delivery/data dependency: ' . $forbidden);
    }
}

$legacyController = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesWorkspaceController.php');
foreach (['public function dashboard(', 'GetSalesDashboardQuery'] as $forbidden) {
    if (str_contains($legacyController, $forbidden)) {
        throw new RuntimeException('VR-002 left duplicate Sales Dashboard ownership: ' . $forbidden);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDashboardPresenter.php');
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'QueryBusInterface'] as $forbidden) {
    if (str_contains($presenter, $forbidden)) {
        throw new RuntimeException('SalesDashboardPresenter leaked business/data dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/dashboard.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosTrendMetric',
    '<twig:CosMoneyMetric',
    '<twig:CosEntityListItem',
    '<twig:CosNextAction',
    '<twig:CosEmptyState',
    'data-sales-surface="dashboard"',
    'data-cos-archetype',
    'data-cos-page-state',
    'data-cos-page-density',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-002 Domain Dashboard composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-002 restored legacy/local presentation: ' . $forbidden);
    }
}

echo "Wave 13 VR-002 /sales/dashboard Domain Dashboard passed.\n";
