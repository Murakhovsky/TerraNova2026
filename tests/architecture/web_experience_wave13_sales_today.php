<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Sales/Query/GetSalesTodayQuery.php',
    'symfony/src/Application/Sales/Query/GetSalesTodayQueryHandler.php',
    'symfony/src/Web/Sales/SalesTodayController.php',
    'symfony/src/Web/Sales/SalesTodayPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesTodayViewModel.php',
    'symfony/templates/experience/sales/today.html.twig',
    'symfony/assets/controllers/sales_today_controller.js',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-005 artifact is missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/sales/today.phtml',
    'app/Interfaces/Web/View/components/sales/today_section.phtml',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-005 legacy ownership must stay deleted: ' . $legacy);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales', 'path: /sales/today', 'SalesTodayController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-005 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesTodayController.php');
foreach ([
    'GetSalesTodayQuery',
    'PageArchetype::OperationalQueue',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    'SalesTodayPresenter',
    "'EntityList'",
    "'ActionBar'",
    "'EmptyState'",
    "'ErrorState'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-005 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Doctrine\\', 'Repository'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-005 controller leaked forbidden dependency: ' . $forbidden);
    }
}

if (is_file($root . '/symfony/src/Web/Sales/SalesPageController.php')) {
    throw new RuntimeException('Retired SalesPageController returned after final Sales cutover.');
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesTodayPresenter.php');
foreach (["'anchor' => 'work'", "'anchor' => 'intelligence'", "'anchor' => 'communications'", "'/sales/deals/'"] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('VR-005 Deal deep-link contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/today.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    '<twig:CosEmptyState',
    'data-controller="sales-today"',
    'data-sales-today-root',
    'data-sales-approval',
    'data-sales-activity-complete',
    'data-sales-activity-reschedule',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-005 Operational Queue composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-005 restored legacy/local presentation: ' . $forbidden);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/sales_today_controller.js');
foreach ([
    '/api/v1/sales/approvals/',
    '/activities/',
    '/complete',
    '/reschedule',
    'X-CSRF-Token',
    'X-Idempotency-Key',
] as $marker) {
    if (!str_contains($stimulus, $marker)) {
        throw new RuntimeException('VR-005 Stimulus parity is incomplete: ' . $marker);
    }
}

echo "Wave 13 VR-005 /sales/today Operational Queue passed.\n";
