<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Sales/Query/GetSalesDealWorkspaceQuery.php',
    'symfony/src/Application/Sales/Query/GetSalesDealWorkspaceQueryHandler.php',
    'symfony/src/Web/Sales/SalesDealController.php',
    'symfony/src/Web/Sales/SalesDealPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesDealViewModel.php',
    'symfony/templates/experience/sales/deal_workspace.html.twig',
    'symfony/assets/controllers/sales_deal_controller.js',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-004 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/sales/deal.phtml')) {
    throw new RuntimeException('VR-004 must delete legacy sales/deal.phtml ownership.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/deals/{id}', 'SalesDealController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-004 route contract is incomplete: ' . $marker);
    }
}
if (str_contains(
    $routes,
    "cos_web_sales_deal:\n  path: /sales/deals/{id}\n  controller: App\\Web\\Sales\\SalesPageController::deal",
)) {
    throw new RuntimeException('VR-004 retained legacy Deal route ownership.');
}

if (is_file($root . '/symfony/src/Web/Sales/SalesPageController.php')) {
    throw new RuntimeException('Legacy SalesPageController returned after VR-008 completed the remaining Sales surface cutover.');
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Sales/Query/GetSalesDealWorkspaceQueryHandler.php');
foreach ([
    'SalesWorkspaceOperationalReadModelInterface',
    'SalesTeamAdministrationInterface',
    'OperationsReadModelInterface',
    'communications(',
    'approvals(',
    'dealIntelligence(',
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('VR-004 query composition is incomplete: ' . $marker);
    }
}
foreach (['App\\Web\\', 'Twig', 'PhtmlRenderer'] as $forbidden) {
    if (str_contains($query, $forbidden)) {
        throw new RuntimeException('VR-004 Application query leaked presentation dependency: ' . $forbidden);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDealController.php');
foreach ([
    'GetSalesDealWorkspaceQuery',
    'PageArchetype::EntityWorkspace',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    'WorkspaceCompositionResolver',
    "new EntityRef('sales.deal'",
    "'sales.deal'",
    'SalesDealPresenter',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-004 Deal controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Doctrine\\', 'Repository'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-004 Deal controller leaked forbidden dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/deal_workspace.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosWorkspace',
    '<twig:CosEntityHeader',
    'class="cos-kpi-strip"',
    'data-controller="sales-deal"',
    'data-sales-deal-workspace',
    'data-sales-stage-form',
    'data-sales-operation-form',
    'data-operation="message"',
    'data-sales-approval',
    'data-sales-intelligence',
    '<twig:CosTimeline',
    'id="work"',
    'id="communications"',
    'id="intelligence"',
    'id="timeline"',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-004 Entity Workspace composition or parity is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-004 restored legacy/local Deal presentation: ' . $forbidden);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/sales_deal_controller.js');
foreach ([
    '/api/v1/sales/opportunities/',
    '/communications',
    '/api/v1/sales/approvals/',
    '/api/v1/sales/actions/',
    'refreshIntelligence',
    'sales-deal#decision',
    'X-CSRF-Token',
    'X-Idempotency-Key',
] as $marker) {
    if (!str_contains($stimulus, $marker)) {
        throw new RuntimeException('VR-004 Stimulus behavior contract is incomplete: ' . $marker);
    }
}
foreach (['innerHTML', 'tn-', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($stimulus, $forbidden)) {
        throw new RuntimeException('VR-004 Stimulus runtime contains forbidden legacy/unsafe presentation: ' . $forbidden);
    }
}

$browser = (string) file_get_contents($root . '/tests/browser/sales_workspace.mjs');
if (!str_contains($browser, "page.locator('.cos-sales-message')")) {
    throw new RuntimeException('Sales browser E2E was not moved to canonical Deal message presentation.');
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
if (!str_contains($registry, "['KpiStrip', 'ContextPanel', 'Timeline', 'ActionBar', 'EmptyState', 'ErrorState']")) {
    throw new RuntimeException('Entity Workspace archetype does not expose the consolidated optional Pattern contract proven by VR-004.');
}

echo "Wave 13 VR-004 /sales/deals/{id} Entity Workspace passed.\n";
