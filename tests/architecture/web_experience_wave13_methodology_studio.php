<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Diagnostic/MethodologyStudioController.php',
    'symfony/templates/experience/diagnostic/methodology_studio.html.twig',
    'symfony/assets/islands/methodology_studio.js',
    'symfony/assets/islands/methodology_studio_v054.js',
    'symfony/assets/islands/methodology_studio_v055.js',
    'symfony/assets/styles/domains/methodology-studio.css',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-021 artifact is missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/methodology_studio/index.phtml',
    'frontend/entrypoints/diagnostics-methodology-studio.js',
    'frontend/features/diagnostics/methodology-studio.js',
    'frontend/features/diagnostics/methodology-studio-v054.js',
    'frontend/features/diagnostics/methodology-studio-v055.js',
    'frontend/features/diagnostics/methodology-studio.css',
    'frontend/features/diagnostics/methodology-studio-v055.css',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-021 retired Methodology Studio asset returned: ' . $legacy);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, 'diagnostics-methodology-studio')) {
    throw new RuntimeException('VR-021 retired Methodology Studio Vite entrypoint returned.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /admin/diagnostics/methodology-studio', 'MethodologyStudioController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-021 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Diagnostic/MethodologyStudioController.php');
foreach ([
    'DiagnosticMethodologyAccess::VIEW',
    'ActiveModuleResolver',
    "isEnabled(\$tenant->organizationId()->value(), 'diagnostic')",
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    "experience/diagnostic/methodology_studio.html.twig",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-021 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'NavigationBuilder', 'pageAssetEntries'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-021 controller retained legacy presentation ownership: ' . $forbidden);
    }
}

$report = (string) file_get_contents($root . '/symfony/src/Web/Diagnostic/DiagnosticPageController.php');
if (str_contains($report, 'public function methodology(')) {
    throw new RuntimeException('VR-021 left duplicate Methodology Studio ownership.');
}
foreach (['DiagnosticRuntimeService', 'public function report('] as $marker) {
    if (!str_contains($report, $marker)) {
        throw new RuntimeException('VR-021 damaged Diagnostic Report ownership: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/diagnostic/methodology_studio.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'data-studio',
    'data-csrf',
    'class="studio-shell"',
    'data-pack-list',
    'data-version',
    'data-tabs',
    'data-entities',
    'data-editor',
    'data-drawer="validation"',
    'data-drawer="simulator"',
    'data-drawer="scenarios"',
    'data-drawer="history"',
    'data-drawer="runs"',
    'data-pack-dialog',
    'data-menu',
    'data-toast',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-021 specialized island composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-021 restored legacy/local Methodology presentation: ' . $forbidden);
    }
}

$app = (string) file_get_contents($root . '/symfony/assets/app.js');
foreach ([
    "./islands/methodology_studio.js",
    "./islands/methodology_studio_v054.js",
    "./islands/methodology_studio_v055.js",
] as $island) {
    if (!str_contains($app, $island)) {
        throw new RuntimeException('VR-021 AssetMapper island is not wired: ' . $island);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($styles, "./domains/methodology-studio.css")) {
    throw new RuntimeException('VR-021 Methodology Studio domain stylesheet is not imported.');
}

foreach ([
    'symfony/assets/islands/methodology_studio.js',
    'symfony/assets/islands/methodology_studio_v054.js',
    'symfony/assets/islands/methodology_studio_v055.js',
] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    if (str_contains($source, '/api/admin/diagnostics')) {
        throw new RuntimeException('VR-021 island restored retired Methodology API: ' . $relative);
    }
    if (!str_contains($source, '/api/v1/admin/diagnostics')) {
        throw new RuntimeException('VR-021 island lost canonical Methodology API: ' . $relative);
    }
}

$css = (string) file_get_contents($root . '/symfony/assets/styles/domains/methodology-studio.css');
foreach (['--tn-', '.tn-workspace-page', '@media(max-width:900px)', '@media(max-width:720px)'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('VR-021 domain stylesheet retained legacy visual ownership: ' . $forbidden);
    }
}
foreach (['@media(max-width:1050px)', '@media(max-width:650px)', '.entity-grid', '.entity-grid__row'] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('VR-021 domain stylesheet contract is incomplete: ' . $marker);
    }
}

echo "Wave 13 VR-021 Methodology Studio passed.\n";
