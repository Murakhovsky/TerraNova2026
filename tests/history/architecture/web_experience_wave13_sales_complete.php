<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredRoutes = [
    '/sales/today' => 'SalesTodayController::index',
    '/sales/pipeline' => 'SalesPipelineController::index',
    '/sales/deals' => 'SalesDealsController::index',
    '/sales/deals/{id}' => 'SalesDealController::index',
    '/sales/director' => 'SalesDirectorController::index',
    '/sales/admin' => 'SalesAdminDashboardController::index',
    '/sales/admin/pipelines' => 'SalesAdminControlController::pipelines',
    '/sales/admin/pipelines/{id}' => 'SalesAdminControlController::pipeline',
    '/sales/admin/rules' => 'SalesAdminControlController::rules',
    '/sales/admin/rules/{id}' => 'SalesAdminControlController::rule',
    '/sales/admin/agents' => 'SalesAdminControlController::agents',
    '/sales/admin/agents/{name}' => 'SalesAdminControlController::agent',
    '/sales/admin/actions' => 'SalesAdminControlController::actions',
    '/sales/admin/teams' => 'SalesAdminControlController::teams',
    '/sales/admin/integrations' => 'SalesAdminControlController::integrations',
    '/sales/admin/health' => 'SalesAdminControlController::health',
];

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ($requiredRoutes as $path => $controller) {
    if (!str_contains($routes, 'path: ' . $path)) {
        throw new RuntimeException('Phase 3 Sales route missing: ' . $path);
    }
    if (!str_contains($routes, $controller)) {
        throw new RuntimeException('Phase 3 canonical Sales route owner missing: ' . $controller);
    }
}

foreach ([
    'symfony/src/Web/Sales/SalesPageController.php',
    'symfony/src/Web/Sales/SalesAdminPageController.php',
    'frontend/entrypoints/sales-workspace.js',
    'frontend/features/sales/workspace.js',
    'frontend/features/sales/workspace.css',
    'frontend/features/sales/rule-editor.js',
    'frontend/features/sales/rule-editor.css',
] as $legacy) {
    if (file_exists($root . '/' . $legacy)) {
        throw new RuntimeException('Phase 3 retired Sales source returned: ' . $legacy);
    }
}

if (is_dir($root . '/frontend/features/sales')) {
    $entries = array_values(array_diff(scandir($root . '/frontend/features/sales') ?: [], ['.', '..']));
    if ($entries !== []) {
        throw new RuntimeException('Phase 3 legacy Sales frontend directory is not empty: ' . implode(', ', $entries));
    }
}

$views = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View', FilesystemIterator::SKIP_DOTS)
);
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($view->getPathname(), strlen($root) + 1));
    if (str_contains($relative, '/sales/') || str_contains($relative, '/sales_admin/')) {
        throw new RuntimeException('Phase 3 Sales visual PHTML must be zero: ' . $relative);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, 'sales-workspace')) {
    throw new RuntimeException('Phase 3 retired sales-workspace Vite entrypoint returned.');
}

$tracker = (string) file_get_contents($root . '/docs/03-architecture/wave13-migration-tracker.md');
foreach ([
    '| VR-005 | `/sales/today` | Workspace | Sales | Operational Queue | P0 | Twig | DONE |',
    '| VR-006 | `/sales/pipeline` | Workspace | Sales | Process / Pipeline | P0 | Twig | DONE |',
    '| VR-007 | `/sales/deals` | Workspace | Sales | Collection | P0 | Twig | DONE |',
    '| VR-008 | `/sales/director` | Workspace | Sales | Executive Dashboard | P0 | Twig | DONE |',
    '| VR-009 | `/sales/admin/*` | System | Sales | System / Control Surface | P0 | Twig | DONE |',
    'Фаза 3 — завершення Sales',
] as $marker) {
    if (!str_contains($tracker, $marker)) {
        throw new RuntimeException('Phase 3 migration tracker is incomplete: ' . $marker);
    }
}

foreach ([
    'symfony/templates/experience/sales/today.html.twig',
    'symfony/templates/experience/sales/pipeline.html.twig',
    'symfony/templates/experience/sales/deals.html.twig',
    'symfony/templates/experience/sales/deal_workspace.html.twig',
    'symfony/templates/experience/sales/director.html.twig',
    'symfony/templates/experience/sales/admin/dashboard.html.twig',
    'symfony/templates/experience/sales/admin/rule.html.twig',
] as $canonical) {
    if (!is_file($root . '/' . $canonical)) {
        throw new RuntimeException('Phase 3 canonical Sales surface missing: ' . $canonical);
    }

    $source = (string) file_get_contents($root . '/' . $canonical);
    foreach (['tn-', 'style=', '<script'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('Phase 3 canonical Sales surface restored legacy presentation: ' . $canonical . ' -> ' . $forbidden);
        }
    }
}

echo "Wave 13 Phase 3 Sales Complete freeze gate passed: Sales PHTML = 0, legacy Sales frontend = 0.\n";
