<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 13 Visualization artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/action_bar'",
    'tn-workspace-page--wide',
    'tn-ui-panel',
    'data-architecture-explorer',
    'data-architecture-endpoint',
    'data-architecture-default-view',
    'data-architecture-view-label',
    'data-architecture-node-count',
    'data-architecture-edge-count',
    'data-architecture-mode',
    'data-architecture-search',
    'data-architecture-domain',
    'data-architecture-depth',
    'data-architecture-fit',
    'data-architecture-reset',
    'data-architecture-types',
    'data-architecture-stage',
    'data-architecture-loading',
    'data-architecture-error',
    'data-architecture-details',
    'data-architecture-backend-diagnostic',
    'type="application/json"',
    'id="cos-architecture-data"',
    'Architecture graph health',
    'cos/architecture/health',
] as $marker) {
    $contains($view, $marker, 'Architecture Explorer canonical/interaction contract is incomplete.');
}
foreach ([
    'tn-breadcrumbs',
    'tn-listing-hero',
    'tn-admin-panel',
    'tn-section-heading',
    'tn-form-status',
    'tn-kicker',
] as $legacyMarker) {
    $notContains($view, $legacyMarker, 'Architecture Explorer must not restore legacy workspace presentation primitives.');
}

$css = $read('frontend/features/cos/architecture-explorer.css');
foreach ([
    '.tn-architecture-toolbar',
    '.tn-architecture-shell',
    '.tn-architecture-sidebar',
    '.tn-architecture-stage-wrap',
    '.tn-architecture-details',
    '@media (max-width: 720px)',
] as $marker) {
    $contains($css, $marker, 'Architecture Explorer specialized visualization CSS is incomplete.');
}
foreach (['tn-architecture-hero', 'tn-architecture-hero__stats'] as $legacyMarker) {
    $notContains($css, $legacyMarker, 'Architecture Explorer hero compatibility CSS must remain retired.');
}

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach ([
    'public function index(Request $request): Response',
    'public function graph(Request $request): Response',
    'public function health(): Response',
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    'GraphMapperInterface',
    "'pageAssetEntries' => ['cos-architecture-explorer']",
    "'workspaceSection' => 'cos'",
    "'workspaceActive' => 'architecture'",
] as $marker) {
    $contains($controller, $marker, 'Architecture Explorer controller ownership is incomplete.');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /cos/architecture',
    'ArchitecturePageController::index',
    'ArchitecturePageController::graph',
    'ArchitecturePageController::health',
] as $marker) {
    $contains($routes, $marker, 'Architecture Explorer route contract is incomplete.');
}

$client = $read('frontend/features/cos/architecture-explorer.js');
foreach ([
    'cytoscape@3.34.3',
    'data-architecture-search',
    'data-architecture-depth',
    'data-architecture-fit',
    'data-architecture-reset',
    'Collapse neighbors',
] as $marker) {
    $contains($client, $marker, 'Architecture Explorer browser interaction contract is incomplete.');
}

$entrypoint = $read('frontend/entrypoints/cos-architecture-explorer.js');
foreach ([
    'hydrateArchitecturePayload',
    "credentials: 'same-origin'",
    "url.searchParams.set('depth', 'all')",
    "await import('../features/cos/architecture-explorer.js')",
] as $marker) {
    $contains($entrypoint, $marker, 'Architecture Explorer hydration contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-production-visualization-adoption.md');
foreach ([
    '# Впровадження Visualization у production UI',
    '## Хвиля 1',
    '### Architecture Explorer',
    '## Межа specialized graph UX',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Visualization production adoption documentation is incomplete.');
}

echo "PHASE 13 Visualization production adoption passed.\n";
