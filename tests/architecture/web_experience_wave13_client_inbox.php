<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Sales/Query/GetClientCaseInboxQuery.php',
    'symfony/src/Application/Sales/Query/GetClientCaseInboxQueryHandler.php',
    'symfony/src/Web/Sales/ClientCaseInboxController.php',
    'symfony/src/Web/Sales/ClientCaseInboxPresenter.php',
    'symfony/src/Web/Sales/ViewModel/ClientCaseInboxViewModel.php',
    'symfony/src/Web/Sales/Component/ClientCaseInboxItem.php',
    'symfony/templates/experience/client_case/inbox.html.twig',
    'symfony/templates/components/client_case/client_case_inbox_item.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-010 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/client_case/inbox.phtml')) {
    throw new RuntimeException('VR-010 must delete legacy Client Case Inbox PHTML.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /client-case/inbox', 'ClientCaseInboxController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-010 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/ClientCaseInboxController.php');
foreach ([
    'GetClientCaseInboxQuery',
    'PageArchetype::OperationalQueue',
    'WorkspaceShellFactory',
    "activeSection: 'clients'",
    "activeItem: 'inbox'",
    "'KpiStrip'",
    "'FilterBar'",
    "'EntityList'",
    'SessionCsrfValidator',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-010 Inbox controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'NavigationBuilder', 'Domains\\Clients', 'Doctrine\\', 'Repository'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-010 Inbox controller leaked forbidden dependency: ' . $forbidden);
    }
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Sales/Query/GetClientCaseInboxQueryHandler.php');
foreach ([
    'ClientCaseReadModelFactoryInterface',
    'inboundFilters(',
    'inboundInbox(',
    'inboundInboxStats(',
    'openCaseOptions(',
    'managerOptions(',
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('VR-010 Application query is incomplete: ' . $marker);
    }
}
foreach (['App\\Web\\', 'Twig', 'PhtmlRenderer'] as $forbidden) {
    if (str_contains($query, $forbidden)) {
        throw new RuntimeException('VR-010 Application query leaked presentation dependency: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/client_case/inbox.html.twig');
foreach ([
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosMetric',
    '<twig:CosFilterBar',
    '<twig:ClientCaseInboxItem',
    'data-client-case-inbox',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-010 Operational Queue composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-010 restored legacy/local Inbox presentation: ' . $forbidden);
    }
}

$item = (string) file_get_contents($root . '/symfony/templates/components/client_case/client_case_inbox_item.html.twig');
foreach ([
    '/client-case/updateInboundRequest/',
    '/client-case/createFromInboundRequest/',
    '/client-case/linkInboundRequest',
    '/client-case/show/',
    '/property/show/',
    'name="csrf_token"',
    'name="return_url"',
    'name="status"',
    'name="assigned_user_id"',
    'name="next_contact_at"',
    'name="activity_type"',
    'name="manager_note"',
    'name="activity_body"',
    'name="priority"',
    'name="request_id"',
    'name="case_id"',
] as $marker) {
    if (!str_contains($item, $marker)) {
        throw new RuntimeException('VR-010 triage mutation parity is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', 'data-controller='] as $forbidden) {
    if (str_contains($item, $forbidden)) {
        throw new RuntimeException('VR-010 Inbox item restored local/JS presentation: ' . $forbidden);
    }
}

$legacyController = (string) file_get_contents($root . '/symfony/src/Web/Sales/ClientCasePageController.php');
if (str_contains($legacyController, 'public function inbox(')) {
    throw new RuntimeException('VR-010 left duplicate Client Case Inbox controller ownership.');
}

echo "Wave 13 VR-010 /client-case/inbox Operational Queue passed.\n";
