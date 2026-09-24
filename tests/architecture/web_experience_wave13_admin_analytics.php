<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Experience/Query/GetWorkspaceAnalyticsQuery.php',
    'symfony/src/Application/Experience/Query/GetWorkspaceAnalyticsQueryHandler.php',
    'symfony/src/Web/Workspace/AnalyticsDashboardController.php',
    'symfony/src/Web/Workspace/AnalyticsDashboardPresenter.php',
    'symfony/src/Web/Workspace/ViewModel/AnalyticsDashboardViewModel.php',
    'symfony/templates/experience/admin/analytics.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-022 artifact missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/admin/analytics.phtml')) {
    throw new RuntimeException('VR-022 must delete legacy Analytics PHTML.');
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Workspace/AnalyticsDashboardController.php');
foreach (['GetWorkspaceAnalyticsQuery', 'PageArchetype::ExecutiveDashboard', 'WorkspaceShellFactory', "'KpiStrip'", "'DataGrid'", "'EntityList'"] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-022 controller contract incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'PropertyFunnelAnalyticsInterface', 'Doctrine\\'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-022 controller leaked forbidden dependency: ' . $forbidden);
    }
}

$handler = (string) file_get_contents($root . '/symfony/src/Application/Experience/Query/GetWorkspaceAnalyticsQueryHandler.php');
foreach (['PropertyFunnelAnalyticsInterface', 'report('] as $marker) {
    if (!str_contains($handler, $marker)) {
        throw new RuntimeException('VR-022 Application Query contract incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/admin/analytics.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosFilterBar', '<twig:CosMetric', '<twig:CosDataGrid', '<twig:CosEntityListItem', 'data-cos-archetype'] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-022 Twig composition incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-022 restored legacy/local presentation: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /admin/analytics', 'AnalyticsDashboardController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-022 route contract incomplete: ' . $marker);
    }
}

$core = (string) file_get_contents($root . '/symfony/src/Web/Workspace/CoreWorkspacePageController.php');
if (str_contains($core, 'public function analytics(') || str_contains($core, 'PropertyFunnelAnalyticsInterface')) {
    throw new RuntimeException('VR-022 left duplicate Analytics ownership in CoreWorkspacePageController.');
}

foreach ([
    'frontend/entrypoints/analytics-workspace.js',
    'frontend/features/analytics/workspace.js',
    'frontend/features/analytics/workspace.css',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('VR-022 retired Analytics frontend restored: ' . $retired);
    }
}

echo "Wave 13 VR-022 /admin/analytics Executive Dashboard passed.\n";
