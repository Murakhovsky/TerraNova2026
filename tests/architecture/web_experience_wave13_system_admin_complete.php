<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$canonical = [
    'symfony/templates/experience/system/control_center.html.twig',
    'symfony/templates/experience/system/architecture.html.twig',
    'symfony/templates/experience/system/methodology_studio.html.twig',
    'symfony/templates/experience/administration/analytics.html.twig',
    'symfony/templates/experience/administration/users.html.twig',
    'symfony/templates/experience/content/manage.html.twig',
    'symfony/templates/experience/content/edit.html.twig',
    'symfony/assets/controllers/architecture_explorer_controller.js',
    'symfony/assets/controllers/diagnostic_methodology_controller.js',
];

foreach ($canonical as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Phase 6 canonical artifact missing: ' . $relative);
    }
}

$legacy = [
    'app/Interfaces/Web/View/cos/index.phtml',
    'app/Interfaces/Web/View/visualization/architecture.phtml',
    'app/Interfaces/Web/View/methodology_studio/index.phtml',
    'app/Interfaces/Web/View/admin/analytics.phtml',
    'app/Interfaces/Web/View/admin/users.phtml',
    'app/Interfaces/Web/View/content/manage.phtml',
    'app/Interfaces/Web/View/content/edit.phtml',
    'frontend/entrypoints/cos-control-center.js',
    'frontend/entrypoints/cos-architecture-explorer.js',
    'frontend/entrypoints/diagnostics-methodology-studio.js',
    'frontend/entrypoints/analytics-workspace.js',
    'frontend/features/cos/control-center.css',
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
    'frontend/features/analytics/workspace.js',
    'frontend/features/analytics/workspace.css',
];

foreach ($legacy as $relative) {
    if (file_exists($root . '/' . $relative)) {
        throw new RuntimeException('Phase 6 legacy artifact restored: ' . $relative);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'App\\Web\\Operations\\ControlCenterPageController::index',
    'App\\Web\\Visualization\\ArchitecturePageController::index',
    'App\\Web\\Diagnostic\\MethodologyStudioController::index',
    'App\\Web\\Administration\\AdministrationAnalyticsController::index',
    'App\\Web\\Administration\\AdministrationUsersController::index',
    'App\\Web\\Content\\ContentAdminPageController::manage',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Phase 6 canonical route owner missing: ' . $marker);
    }
}

$tracker = (string) file_get_contents($root . '/docs/03-architecture/wave13-migration-tracker.md');
foreach (range(19, 24) as $number) {
    $id = sprintf('VR-%03d', $number);
    if (preg_match('/\| ' . preg_quote($id, '/') . ' \|[^\n]*\| DONE \|/', $tracker) !== 1) {
        throw new RuntimeException('Phase 6 tracker unit is not DONE: ' . $id);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
foreach ([
    'cos-control-center',
    'cos-architecture-explorer',
    'diagnostics-methodology-studio',
    'analytics-workspace',
] as $legacyEntry) {
    if (str_contains($vite, $legacyEntry)) {
        throw new RuntimeException('Phase 6 page-specific Vite entrypoint restored: ' . $legacyEntry);
    }
}

$architecture = (string) file_get_contents($root . '/symfony/templates/experience/system/architecture.html.twig');
$methodology = (string) file_get_contents($root . '/symfony/templates/experience/system/methodology_studio.html.twig');
if (!str_contains($architecture, 'data-controller="architecture-explorer"')) {
    throw new RuntimeException('Architecture Explorer specialized island contract missing.');
}
if (!str_contains($methodology, 'data-controller="diagnostic-methodology"')) {
    throw new RuntimeException('Methodology Studio specialized island contract missing.');
}

echo "Wave 13 Phase 6 System / Admin UI complete: VR-019...024 DONE, legacy ownership retired.\n";
