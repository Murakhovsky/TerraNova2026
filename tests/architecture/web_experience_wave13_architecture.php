<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Visualization/Query/GetArchitectureExplorerQuery.php',
    'symfony/src/Application/Visualization/Query/GetArchitectureExplorerQueryHandler.php',
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/src/Web/Visualization/ArchitectureExplorerPresenter.php',
    'symfony/src/Web/Visualization/ViewModel/ArchitectureExplorerViewModel.php',
    'symfony/templates/experience/visualization/architecture.html.twig',
    'frontend/entrypoints/cos-architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-020 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/visualization/architecture.phtml')) {
    throw new RuntimeException('VR-020 retired Architecture PHTML returned.');
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach ([
    'GetArchitectureExplorerQuery',
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    'ArchitectureExplorerPresenter',
    'public function graph(',
    'public function health(',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-020 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'NavigationBuilder'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-020 controller retained legacy shell ownership: ' . $forbidden);
    }
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Visualization/Query/GetArchitectureExplorerQueryHandler.php');
foreach ([
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    'GraphMapperInterface',
    "'views' => \$views",
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('VR-020 Application Query composition is incomplete: ' . $marker);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Visualization/ArchitectureExplorerPresenter.php');
foreach (['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT', 'JSON_INVALID_UTF8_SUBSTITUTE'] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('VR-020 safe graph bootstrap serialization is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/visualization/architecture.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosStatus',
    '<twig:CosEmptyState',
    'data-architecture-explorer',
    'data-architecture-endpoint="/cos/architecture/graph"',
    'data-architecture-mode',
    'data-architecture-search',
    'data-architecture-domain',
    'data-architecture-depth',
    'data-architecture-types',
    'data-architecture-stage',
    'data-architecture-details',
    'type="application/json"',
    'id="cos-architecture-data"',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-020 System Control Surface / island contract is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style='] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-020 restored legacy/local outer-shell presentation: ' . $forbidden);
    }
}

foreach ([
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    if (str_contains($source, 'tn-architecture-')) {
        throw new RuntimeException('VR-020 retained legacy Architecture selector namespace: ' . $relative);
    }
    if (!str_contains($source, 'cos-architecture-')) {
        throw new RuntimeException('VR-020 canonical Architecture selector namespace is missing: ' . $relative);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['/cos/architecture', '/cos/architecture/graph', '/cos/architecture/health'] as $route) {
    if (!str_contains($routes, 'path: ' . $route)) {
        throw new RuntimeException('VR-020 route contract is missing: ' . $route);
    }
}

echo "Wave 13 VR-020 Architecture Explorer passed.\n";
