<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing WEB V0.8 Analytics artifact: ' . $path);
    return (string) file_get_contents($full);
};

$controller = $read('symfony/src/Web/Workspace/AnalyticsDashboardController.php');
$template = $read('symfony/templates/experience/admin/analytics.html.twig');
$handler = $read('symfony/src/Application/Experience/Query/GetWorkspaceAnalyticsQueryHandler.php');
$routes = $read('symfony/config/routes.yaml');
$vite = $read('vite.config.js');
$assets = $read('tests/architecture/frontend_assets.php');

foreach (['GetWorkspaceAnalyticsQuery', 'PageArchetype::ExecutiveDashboard', 'WorkspaceShellFactory', 'AnalyticsDashboardPresenter'] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Wave 13 Analytics controller contract incomplete: ' . $marker);
    }
}
foreach (['PropertyFunnelAnalyticsInterface', 'report('] as $marker) {
    if (!str_contains($handler, $marker)) {
        throw new RuntimeException('Analytics Application Query boundary incomplete: ' . $marker);
    }
}
foreach (['<twig:CosPageHeader', '<twig:CosFilterBar', '<twig:CosMetric', '<twig:CosDataGrid', '<twig:CosEntityListItem', 'data-cos-archetype'] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Analytics Twig composition incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('Analytics restored legacy/local presentation: ' . $forbidden);
    }
}
foreach (['path: /admin/analytics', 'AnalyticsDashboardController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Analytics route cutover incomplete: ' . $marker);
    }
}
foreach ([
    'app/Interfaces/Web/View/admin/analytics.phtml',
    'frontend/entrypoints/analytics-workspace.js',
    'frontend/features/analytics/workspace.js',
    'frontend/features/analytics/workspace.css',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('Retired Analytics presentation restored: ' . $retired);
    }
}
if (str_contains($vite, "'analytics-workspace'") || str_contains($assets, "'analytics-workspace'")) {
    throw new RuntimeException('Retired Analytics Vite entrypoint is still registered.');
}

echo "WEB V0.8 analytics workspace now uses Wave 13 Executive Dashboard runtime.\n";
