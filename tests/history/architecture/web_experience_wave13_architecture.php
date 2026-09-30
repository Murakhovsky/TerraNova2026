<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php',
    'symfony/src/Application/Visualization/Query/GetArchitectureOverviewQueryHandler.php',
    'symfony/src/Application/Visualization/Query/GetArchitectureProjectionQueryHandler.php',
    'symfony/src/Application/Visualization/Query/GetArchitectureHealthQueryHandler.php',
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/src/Web/Visualization/ArchitectureOverviewPresenter.php',
    'symfony/src/Web/Visualization/ViewModel/ArchitectureOverviewViewModel.php',
    'symfony/templates/experience/system/architecture.html.twig',
    'symfony/assets/controllers/architecture_explorer_controller.js',
    'symfony/assets/styles/domains/system-architecture.css',
];
foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-020 artifact missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/visualization/architecture.phtml',
    'frontend/entrypoints/cos-architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
] as $legacy) {
    if (file_exists($root . '/' . $legacy)) {
        throw new RuntimeException('VR-020 legacy Architecture artifact restored: ' . $legacy);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach (['QueryBusInterface', 'GetArchitectureOverviewQuery', 'GetArchitectureProjectionQuery', 'GetArchitectureHealthQuery', 'PageArchetype::SystemControlSurface'] as $marker) {
    if (!str_contains($controller, $marker)) throw new RuntimeException('VR-020 controller contract incomplete: ' . $marker);
}
foreach (['PhtmlRenderer', 'NavigationBuilder', 'GraphProviderInterface', 'GraphMapperInterface', 'GraphProjectionRegistryInterface', 'Infrastructure\\'] as $forbidden) {
    if (str_contains($controller, $forbidden)) throw new RuntimeException('VR-020 Web controller leaked direct graph/legacy dependency: ' . $forbidden);
}

$application = (string) file_get_contents($root . '/symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
foreach (['GraphProviderInterface', 'GraphProjectionRegistryInterface', 'GraphHealthAnalyzerInterface', 'GraphMapperInterface', 'new GraphView('] as $marker) {
    if (!str_contains($application, $marker)) throw new RuntimeException('VR-020 Application graph boundary incomplete: ' . $marker);
}
foreach (['App\\Web\\', 'Infrastructure\\'] as $forbidden) {
    if (str_contains($application, $forbidden)) throw new RuntimeException('VR-020 Application layer leaked forbidden dependency: ' . $forbidden);
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/system/architecture.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    'data-controller="architecture-explorer"',
    'data-architecture-explorer-endpoint-value="/cos/architecture/graph"',
    'data-architecture-explorer-target="stage"',
    'data-architecture-explorer-target="details"',
    'Graph Health',
] as $marker) {
    if (!str_contains($template, $marker)) throw new RuntimeException('VR-020 canonical island composition incomplete: ' . $marker);
}
foreach (['tn-', 'style=', '<script', '<table', 'application/json', 'cos-architecture-data'] as $forbidden) {
    if (str_contains($template, $forbidden)) throw new RuntimeException('VR-020 restored embedded/legacy presentation: ' . $forbidden);
}

$client = (string) file_get_contents($root . '/symfony/assets/controllers/architecture_explorer_controller.js');
foreach (['cytoscape@3.34.3', 'loadProjection(', "credentials: 'same-origin'", 'renderTypeFilters(', 'layoutOptions(', 'replaceChildren('] as $marker) {
    if (!str_contains($client, $marker)) throw new RuntimeException('VR-020 Stimulus island incomplete: ' . $marker);
}
foreach (['innerHTML', 'tn-', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($client, $forbidden)) throw new RuntimeException('VR-020 Stimulus island restored unsafe/legacy state: ' . $forbidden);
}

$css = (string) file_get_contents($root . '/symfony/assets/styles/domains/system-architecture.css');
foreach (['.cos-architecture__shell', '@media (max-width: 1050px)', '@media (max-width: 650px)', 'var(--cos-color-'] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException('VR-020 domain CSS incomplete: ' . $marker);
}
foreach (['tn-', '#fff', '#000'] as $forbidden) {
    if (str_contains($css, $forbidden)) throw new RuntimeException('VR-020 domain CSS bypasses canonical visual ownership: ' . $forbidden);
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, 'cos-architecture-explorer')) {
    throw new RuntimeException('VR-020 legacy Architecture Vite entrypoint must stay retired.');
}

echo "Wave 13 VR-020 Architecture Explorer passed.\n";
