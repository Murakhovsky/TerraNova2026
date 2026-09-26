<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Operations/Query/GetControlCenterQuery.php',
    'symfony/src/Application/Operations/Query/GetControlCenterQueryHandler.php',
    'symfony/src/Web/Operations/ControlCenterPageController.php',
    'symfony/src/Web/Operations/ControlCenterPresenter.php',
    'symfony/src/Web/Operations/ViewModel/ControlCenterViewModel.php',
    'symfony/templates/experience/system/control_center.html.twig',
];
foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-019 artifact missing: ' . $relative);
    }
}
foreach ([
    'app/Interfaces/Web/View/cos/index.phtml',
    'frontend/entrypoints/cos-control-center.js',
    'frontend/features/cos/control-center.css',
] as $legacy) {
    if (file_exists($root . '/' . $legacy)) {
        throw new RuntimeException('VR-019 legacy artifact restored: ' . $legacy);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Operations/ControlCenterPageController.php');
foreach ([
    'GetControlCenterQuery',
    'QueryBusInterface',
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    'ControlCenterPresenter',
    'OperationsMutationCommand',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-019 controller contract incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'NavigationBuilder', 'OperationsReadModelInterface'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-019 controller retained legacy/direct read ownership: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/system/control_center.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'data-cos-control-center',
    'name="csrf_token"',
    'item.executeUrl',
    'item.approveUrl',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-019 System Control Surface incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table', '|raw'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-019 restored unsafe/legacy presentation: ' . $forbidden);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Operations/ControlCenterPresenter.php');
foreach (['events', 'rules', 'agents', 'policies', 'integrations', 'decisions', 'actions', 'approvals', 'results', 'audit'] as $section) {
    if (!str_contains($presenter, "'".$section."'")) {
        throw new RuntimeException('VR-019 presenter section missing: ' . $section);
    }
}

echo "Wave 13 VR-019 COS Control Center passed.\n";
