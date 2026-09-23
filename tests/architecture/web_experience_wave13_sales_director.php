<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Sales/Query/GetSalesDirectorDashboardQuery.php',
    'symfony/src/Application/Sales/Query/GetSalesDirectorDashboardQueryHandler.php',
    'symfony/src/Web/Sales/SalesDirectorController.php',
    'symfony/src/Web/Sales/SalesDirectorPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesDirectorViewModel.php',
    'symfony/src/Web/Sales/ViewModel/SalesDirectorGridViewModel.php',
    'symfony/templates/experience/sales/director.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-008 artifact is missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/sales/director.phtml',
    'app/Interfaces/Web/View/components/sales/director_velocity.phtml',
    'app/Interfaces/Web/View/components/sales/director_attribution.phtml',
    'app/Interfaces/Web/View/components/sales/director_currency_policy.phtml',
    'symfony/src/Web/Sales/SalesPageController.php',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-008 legacy ownership must stay deleted: ' . $legacy);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/director', 'SalesDirectorController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-008 route contract is incomplete: ' . $marker);
    }
}

$handler = (string) file_get_contents($root . '/symfony/src/Application/Sales/Query/GetSalesDirectorDashboardQueryHandler.php');
foreach (['SalesDirectorCockpitService', 'DateTimeImmutable', 'director->overview'] as $marker) {
    if (!str_contains($handler, $marker)) {
        throw new RuntimeException('VR-008 Application Query contract is incomplete: ' . $marker);
    }
}
foreach (['App\Web\', 'Twig', 'PhtmlRenderer'] as $forbidden) {
    if (str_contains($handler, $forbidden)) {
        throw new RuntimeException('VR-008 Application Query leaked presentation dependency: ' . $forbidden);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDirectorController.php');
foreach ([
    'GetSalesDirectorDashboardQuery',
    'PageArchetype::ExecutiveDashboard',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    "'KpiStrip'",
    "'FilterBar'",
    "'DataGrid'",
    'SalesDirectorPresenter',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-008 controller contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/director.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    'class="cos-kpi-strip"',
    '<twig:CosDataGrid',
    'Historical Sales Intelligence',
    'Pipeline and forecast by currency',
    'Historical funnel',
    'Manager performance',
    'Risk & explainability',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-008 Executive Dashboard composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-008 restored legacy/local Director presentation: ' . $forbidden);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDirectorPresenter.php');
foreach ([
    'pipeline_by_currency',
    'forecast_by_currency',
    'at_risk_by_currency',
    'stage_conversion',
    'manager_performance',
    'currency_policy',
    'Currencies are never mixed',
] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('VR-008 Director projection lost semantic contract: ' . $marker);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
foreach (["'FilterBar'", "'DataGrid'", "'Toolbar'"] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('Executive Dashboard additive Pattern contract is incomplete: ' . $marker);
    }
}

echo "Wave 13 VR-008 /sales/director Executive Dashboard passed.\n";
