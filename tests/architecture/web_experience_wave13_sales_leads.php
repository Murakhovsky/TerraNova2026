<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Sales/SalesLeadsController.php',
    'symfony/src/Web/Sales/SalesLeadsPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesLeadsViewModel.php',
    'symfony/templates/experience/sales/leads.html.twig',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-003 artifact is missing: ' . $relative);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/leads', 'SalesLeadsController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-003 route contract is incomplete: ' . $marker);
    }
}
if (str_contains($routes, 'SalesWorkspaceController::leads')) {
    throw new RuntimeException('VR-003 retained duplicate Lead List route ownership.');
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
if (!str_contains($services, 'App\Web\Sales\SalesLeadsController:')) {
    throw new RuntimeException('VR-003 SalesLeadsController service wiring is missing.');
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesLeadsController.php');
foreach ([
    'ListSalesLeadsQuery',
    'SalesAdminQuery',
    'PageArchetype::Collection',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    'SalesLeadsPresenter',
    "experience/sales/leads.html.twig",
    "'FilterBar'",
    "'EntityList'",
    "'ActionBar'",
    "'error'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-003 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-003 controller leaked forbidden delivery/data dependency: ' . $forbidden);
    }
}

$workspace = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesWorkspaceController.php');
foreach (['public function leads(', 'ListSalesLeadsQuery'] as $forbidden) {
    if (str_contains($workspace, $forbidden)) {
        throw new RuntimeException('VR-003 left duplicate Lead List ownership: ' . $forbidden);
    }
}
foreach (['public function lead(', "new EntityRef('sales.lead'"] as $requiredWorkspace) {
    if (!str_contains($workspace, $requiredWorkspace)) {
        throw new RuntimeException('VR-003 damaged Lead Workspace ownership: ' . $requiredWorkspace);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesLeadsPresenter.php');
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'QueryBusInterface'] as $forbidden) {
    if (str_contains($presenter, $forbidden)) {
        throw new RuntimeException('SalesLeadsPresenter leaked business/data dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/leads.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    '<twig:CosEmptyState',
    'data-sales-surface="lead-list"',
    'data-cos-archetype',
    'data-cos-page-state',
    'data-cos-page-density',
    'data-controller="sales-lead"',
    'data-sales-lead-status',
    'data-sales-lead-owner',
    'data-sales-lead-deal',
    'data-sales-lead-followup',
    'leads.nextPageUrl()',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-003 Collection composition or mutation parity is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-003 restored legacy/local presentation: ' . $forbidden);
    }
}

$freeze = (string) file_get_contents($root . '/tests/architecture/web_platform_v1_freeze.php');
if (!str_contains($freeze, 'SalesLeadsController::index')) {
    throw new RuntimeException('Web Platform production anchor was not updated for VR-003 controller ownership.');
}

echo "Wave 13 VR-003 /sales/leads Collection passed.\n";
