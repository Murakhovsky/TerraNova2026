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
        throw new RuntimeException('VR-005 legacy Today ownership returned: ' . $legacy);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales', 'path: /sales/today', 'SalesTodayController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-005 route contract is incomplete: ' . $marker);
    }
}
if (str_contains($routes, 'SalesPageController::today')) {
    throw new RuntimeException('VR-005 retained legacy Today route ownership on /sales or /sales/today.');
}

$legacyController = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesPageController.php');
if (str_contains($legacyController, 'public function today(')) {
    throw new RuntimeException('VR-005 retained Today ownership in SalesPageController.');
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Sales/Query/GetSalesTodayQueryHandler.php');
foreach (['SalesWorkspaceReadModelInterface', '->today('] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('VR-005 query contract is incomplete: ' . $marker);
    }
}
foreach (['App\\Web\\', 'Twig', 'PhtmlRenderer'] as $forbidden) {
    if (str_contains($query, $forbidden)) {
        throw new RuntimeException('VR-005 Application query leaked presentation dependency: ' . $forbidden);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesTodayController.php');
foreach ([
    'GetSalesTodayQuery',
    'PageArchetype::OperationalQueue',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    'SalesTodayPresenter',
    "'PageHeader'",
    "'EntityList'",
    "'ActionBar'",
    "'EmptyState'",
    "'ErrorState'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-005 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-005 controller leaked forbidden dependency: ' . $forbidden);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesTodayPresenter.php');
foreach ([
    'needs_approval',
    'must_do',
    'ai_recommended',
    'overdue',
    'new_replies',
    'meetings',
    'followups',
    'waiting_for_client',
    "'#' . \$anchor",
] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('VR-005 presenter lost Today semantics: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'QueryBusInterface'] as $forbidden) {
    if (str_contains($presenter, $forbidden)) {
        throw new RuntimeException('VR-005 presenter leaked business/data dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/today.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosActionBar',
    '<twig:CosEntityListItem',
    '<twig:CosEmptyState',
    '<twig:CosAlert',
    'data-controller="sales-today"',
    'data-sales-today-root',
    'data-sales-today-status',
    'data-sales-approval',
    'data-sales-activity-complete',
    'data-sales-activity-reschedule',
    'data-cos-archetype',
    'data-cos-page-state',
    'data-cos-page-density',
    'My Work',
    'Team',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-005 Operational Queue composition or parity is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-005 restored legacy/local presentation: ' . $forbidden);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/sales_today_controller.js');
foreach ([
    '/api/v1/sales/approvals/',
    '/api/v1/sales/opportunities/',
    '/activities/',
    '/complete',
    '/reschedule',
    'X-CSRF-Token',
    'X-Idempotency-Key',
] as $marker) {
    if (!str_contains($stimulus, $marker)) {
        throw new RuntimeException('VR-005 Today Stimulus behavior is incomplete: ' . $marker);
    }
}
foreach (['innerHTML', 'tn-', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($stimulus, $forbidden)) {
        throw new RuntimeException('VR-005 Today Stimulus restored forbidden presentation state: ' . $forbidden);
    }
}

$legacyJs = (string) file_get_contents($root . '/frontend/features/sales/workspace.js');
foreach (['const initToday =', "querySelectorAll('[data-sales-today-root]')"] as $retired) {
    if (str_contains($legacyJs, $retired)) {
        throw new RuntimeException('VR-005 retained legacy Today browser ownership: ' . $retired);
    }
}

require_once $root . '/symfony/src/Web/Experience/Visual/VisualStability.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetype.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeDefinition.php';
require_once $root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php';

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PageArchetypeRegistry;
use App\Web\Experience\Visual\VisualStability;

$operationalQueue = (new PageArchetypeRegistry())->get(PageArchetype::OperationalQueue);
if ($operationalQueue->stability !== VisualStability::Experimental) {
    throw new RuntimeException('Operational Queue must remain experimental after the first production migration.');
}
foreach (['EmptyState', 'ErrorState'] as $pattern) {
    if (!in_array($pattern, $operationalQueue->optionalPatterns, true)) {
        throw new RuntimeException('Operational Queue contract is missing Today state Pattern: ' . $pattern);
    }
}

echo "Wave 13 VR-005 /sales/today Operational Queue passed.\n";
