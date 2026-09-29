<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Diagnostic/Methodology/GetMethodologyStudioAccessQuery.php',
    'symfony/src/Application/Diagnostic/Methodology/GetMethodologyStudioAccessQueryHandler.php',
    'symfony/src/Web/Diagnostic/MethodologyStudioController.php',
    'symfony/templates/experience/system/methodology_studio.html.twig',
    'symfony/assets/controllers/diagnostic_methodology_controller.js',
    'symfony/assets/islands/diagnostic_methodology/base.js',
    'symfony/assets/islands/diagnostic_methodology/v054.js',
    'symfony/assets/islands/diagnostic_methodology/v055.js',
    'symfony/assets/styles/domains/diagnostic-methodology.css',
];
foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) throw new RuntimeException('VR-021 artifact missing: ' . $relative);
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
    if (file_exists($root . '/' . $legacy)) throw new RuntimeException('VR-021 legacy Methodology artifact restored: ' . $legacy);
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Diagnostic/MethodologyStudioController.php');
foreach (['GetMethodologyStudioAccessQuery','PageArchetype::SystemControlSurface','WorkspaceShellFactory','PagePresentationFactory','SessionCsrfValidator'] as $marker) {
    if (!str_contains($controller, $marker)) throw new RuntimeException('VR-021 controller contract incomplete: ' . $marker);
}
foreach (['PhtmlRenderer','NavigationBuilder','DiagnosticMethodologyAccess','ActiveModuleResolver'] as $forbidden) {
    if (str_contains($controller, $forbidden)) throw new RuntimeException('VR-021 Web controller leaked direct/legacy dependency: ' . $forbidden);
}

$access = (string) file_get_contents($root . '/symfony/src/Application/Diagnostic/Methodology/GetMethodologyStudioAccessQueryHandler.php');
foreach (['DiagnosticMethodologyAccess::VIEW','ActiveModuleResolver',"isEnabled(\$organizationId, 'diagnostic')"] as $marker) {
    if (!str_contains($access, $marker)) throw new RuntimeException('VR-021 access boundary incomplete: ' . $marker);
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/system/methodology_studio.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'data-controller="diagnostic-methodology"',
    'data-studio',
    'data-editor',
    'data-studio-action="publish"',
    'data-drawer="validation"',
    'data-drawer="simulator"',
    'data-drawer="scenarios"',
    'data-drawer="history"',
    'data-drawer="runs"',
    'turbo-cache-control',
] as $marker) {
    if (!str_contains($template, $marker)) throw new RuntimeException('VR-021 canonical editor composition incomplete: ' . $marker);
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) throw new RuntimeException('VR-021 restored legacy/local template presentation: ' . $forbidden);
}

$controllerJs = (string) file_get_contents($root . '/symfony/assets/controllers/diagnostic_methodology_controller.js');
foreach (['bootMethodologyStudioBase','bootMethodologyStudioV054','bootMethodologyStudioV055'] as $marker) {
    if (!str_contains($controllerJs, $marker)) throw new RuntimeException('VR-021 island bootstrap incomplete: ' . $marker);
}

foreach (['base.js','v054.js','v055.js'] as $file) {
    $source=(string)file_get_contents($root.'/symfony/assets/islands/diagnostic_methodology/'.$file);
    if (str_contains($source,'data-action')) throw new RuntimeException('VR-021 specialized island still conflicts with Stimulus data-action namespace: '.$file);
    if (!str_contains($source,'data-studio-action') && $file !== 'v054.js') throw new RuntimeException('VR-021 specialized action namespace missing: '.$file);
}

$css=(string)file_get_contents($root.'/symfony/assets/styles/domains/diagnostic-methodology.css');
foreach (['var(--cos-color-', '@media (max-width: 1050px)', '@media (max-width: 650px)'] as $marker) {
    if (!str_contains($css,$marker)) throw new RuntimeException('VR-021 canonical domain CSS incomplete: '.$marker);
}
foreach (['var(--tn-', '#17202a', '#176b4d', '@media(max-width:900px)', '@media(max-width:720px)'] as $forbidden) {
    if (str_contains($css,$forbidden)) throw new RuntimeException('VR-021 CSS restored legacy/custom visual contract: '.$forbidden);
}

$vite=(string)file_get_contents($root.'/vite.config.js');
if (str_contains($vite,'diagnostics-methodology-studio')) throw new RuntimeException('VR-021 legacy Methodology Vite entrypoint restored.');

echo "Wave 13 VR-021 Methodology Studio passed.\n";
