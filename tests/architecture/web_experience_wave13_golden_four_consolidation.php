<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$units = [
    [
        'id' => 'VR-001',
        'route' => '/admin',
        'controller' => 'symfony/src/Web/Workspace/ExecutiveDashboardController.php',
        'template' => 'symfony/templates/experience/admin/dashboard.html.twig',
        'archetype' => 'PageArchetype::ExecutiveDashboard',
        'patterns' => ["'PageHeader'", "'KpiStrip'", "'EntityList'", "'EmptyState'", "'ErrorState'"],
        'templateMarkers' => ['<twig:CosPageHeader', 'class="cos-kpi-strip"', '<twig:CosEntityListItem', '<twig:CosEmptyState', '<twig:CosAlert'],
    ],
    [
        'id' => 'VR-002',
        'route' => '/sales/dashboard',
        'controller' => 'symfony/src/Web/Sales/SalesDashboardController.php',
        'template' => 'symfony/templates/experience/sales/dashboard.html.twig',
        'archetype' => 'PageArchetype::DomainDashboard',
        'patterns' => ["'PageHeader'", "'KpiStrip'", "'EntityList'", "'EmptyState'", "'ErrorState'"],
        'templateMarkers' => ['<twig:CosPageHeader', 'class="cos-kpi-strip"', '<twig:CosEntityListItem', '<twig:CosEmptyState', '<twig:CosAlert'],
    ],
    [
        'id' => 'VR-003',
        'route' => '/sales/leads',
        'controller' => 'symfony/src/Web/Sales/SalesLeadsController.php',
        'template' => 'symfony/templates/experience/sales/leads.html.twig',
        'archetype' => 'PageArchetype::Collection',
        'patterns' => ["'PageHeader'", "'FilterBar'", "'EntityList'", "'ActionBar'", "'EmptyState'", "'ErrorState'"],
        'templateMarkers' => ['<twig:CosPageHeader', '<twig:CosFilterBar', '<twig:CosEntityListItem', '<twig:CosActionBar', '<twig:CosEmptyState', '<twig:CosAlert'],
    ],
    [
        'id' => 'VR-004',
        'route' => '/sales/deals/{id}',
        'controller' => 'symfony/src/Web/Sales/SalesDealController.php',
        'template' => 'symfony/templates/experience/sales/deal_workspace.html.twig',
        'archetype' => 'PageArchetype::EntityWorkspace',
        'patterns' => ["'WorkspaceHeader'", "'EntityHeader'", "'KpiStrip'", "'ContextPanel'", "'ActionBar'", "'Timeline'", "'EmptyState'", "'ErrorState'"],
        'templateMarkers' => ['<twig:CosWorkspace', '<twig:CosEntityHeader', 'class="cos-kpi-strip"', '<twig:CosActionBar', '<twig:CosTimeline', '<twig:CosAlert'],
    ],
];

foreach ($units as $unit) {
    foreach (['controller', 'template'] as $key) {
        if (!is_file($root . '/' . $unit[$key])) {
            throw new RuntimeException($unit['id'] . ' Golden Four artifact is missing: ' . $unit[$key]);
        }
    }

    $controller = (string) file_get_contents($root . '/' . $unit['controller']);
    if (!str_contains($controller, $unit['archetype'])) {
        throw new RuntimeException($unit['id'] . ' lost its canonical archetype.');
    }
    foreach ($unit['patterns'] as $pattern) {
        if (!str_contains($controller, $pattern)) {
            throw new RuntimeException($unit['id'] . ' lost declared production Pattern: ' . $pattern);
        }
    }

    $template = (string) file_get_contents($root . '/' . $unit['template']);
    foreach ([
        'data-cos-archetype',
        'data-cos-page-state',
        'data-cos-page-density',
        ...$unit['templateMarkers'],
    ] as $marker) {
        if (!str_contains($template, $marker)) {
            throw new RuntimeException($unit['id'] . ' lacks Golden Four render evidence: ' . $marker);
        }
    }

    foreach (['tn-', 'style=', '<script'] as $forbidden) {
        if (str_contains($template, $forbidden)) {
            throw new RuntimeException($unit['id'] . ' contains forbidden canonical presentation: ' . $forbidden);
        }
    }
}

$executive = (string) file_get_contents($root . '/symfony/src/Web/Workspace/ExecutiveDashboardController.php');
foreach (["'ActionBar'", "'StatGrid'", "'PermissionState'"] as $falseDeclaration) {
    if (str_contains($executive, $falseDeclaration)) {
        throw new RuntimeException('Executive Dashboard still declares an unrendered Pattern: ' . $falseDeclaration);
    }
}

$workspace = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_workspace.html.twig');
foreach ([
    '<twig:CosWorkspaceHeader',
    '<twig:CosContextPanel',
    '<twig:CosActivityPanel',
    '<twig:CosAIContext',
    'aria-label="Workspace context"',
] as $marker) {
    if (!str_contains($workspace, $marker)) {
        throw new RuntimeException('Entity Workspace indirect Pattern evidence is missing: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'path: /admin',
    'path: /sales/dashboard',
    'path: /sales/leads',
    'path: /sales/deals/{id}',
] as $route) {
    if (!str_contains($routes, $route)) {
        throw new RuntimeException('Golden Four production route is missing: ' . $route);
    }
}

foreach ([
    'app/Interfaces/Web/View/admin/index.phtml',
    'app/Interfaces/Web/View/sales/dashboard.phtml',
    'app/Interfaces/Web/View/sales/leads.phtml',
    'app/Interfaces/Web/View/sales/deal.phtml',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('Golden Four legacy ownership returned: ' . $legacy);
    }
}

echo "Wave 13 Phase 2.5 Golden Four composition evidence passed.\n";
