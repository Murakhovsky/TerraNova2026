<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Kernel/Visualization/Graph/GraphPayloadMapperInterface.php',
    'app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php',
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/config/routes.yaml',
    'app/Interfaces/Web/View/visualization/architecture.phtml',
    'frontend/entrypoints/cos-architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.3 file: ' . $path);
}
foreach ([
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
] as $retired) {
    $assert(!file_exists($root . '/' . $retired), 'Retired Phalcon Visualization delivery restored: ' . $retired);
}

$kernelRoot = $root . '/app/Kernel/Visualization';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kernelRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = strtolower((string) file_get_contents($file->getPathname()));
    $assert(!str_contains($source, 'cytoscape'), 'Kernel Visualization must remain renderer-independent: ' . $file->getPathname());
}

$port = $read('app/Kernel/Visualization/Graph/GraphPayloadMapperInterface.php');
$assert(str_contains($port, 'public function map(Graph $graph): array;'), 'Graph payload mapper Kernel port is invalid.');

$mapper = $read('app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php');
$assert(str_contains($mapper, 'implements GraphPayloadMapperInterface'), 'Cytoscape mapper must implement the Kernel mapper port.');
$assert(str_contains($mapper, 'public function map(Graph $graph): array'), 'Cytoscape mapper must consume the canonical Graph.');

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach (['GraphProviderInterface', 'GraphPayloadMapperInterface', 'public function index(', 'public function graph(', 'public function health('] as $marker) {
    $assert(str_contains($controller, $marker), 'Symfony Architecture Explorer missing: ' . $marker);
}
$assert(!str_contains($controller, 'Infrastructure\\Visualization'), 'Symfony Web controller must not compile against Visualization Infrastructure.');

$routes = $read('symfony/config/routes.yaml');
foreach (['cos_web_architecture:', 'cos_web_architecture_graph:', 'cos_web_architecture_health:', 'path: /cos/architecture'] as $marker) {
    $assert(str_contains($routes, $marker), 'Canonical Symfony Architecture route missing: ' . $marker);
}

$module = $read('app/Interfaces/Web/Module.php');
$assert(!str_contains($module, 'VisualizationRoutes'), 'Phalcon Web module must not register migrated Visualization routes.');

$services = $read('symfony/config/services.yaml');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphPayloadMapperInterface:'), 'Symfony composition must bind the graph mapper port.');
$assert(str_contains($services, 'alias: Infrastructure\\Visualization\\Cytoscape\\CytoscapeGraphMapper'), 'Cytoscape adapter alias missing.');

$vite = $read('vite.config.js');
$assert(str_contains($vite, "'cos-architecture-explorer'"), 'Architecture Explorer Vite entry missing.');
$client = $read('frontend/features/cos/architecture-explorer.js');
foreach (['data-architecture-search', 'data-architecture-depth', 'data-architecture-fit', 'data-architecture-reset', 'Collapse neighbors'] as $marker) {
    $assert(str_contains($client, $marker), 'Explorer interaction marker missing: ' . $marker);
}
$assert(str_contains($client, 'cytoscape@3.34.3'), 'Cytoscape browser dependency must be version-pinned.');

$entrypoint = $read('frontend/entrypoints/cos-architecture-explorer.js');
$assert(str_contains($entrypoint, 'hydrateArchitecturePayload'), 'Explorer entrypoint must recover an unusable embedded payload.');
$assert(str_contains($entrypoint, "credentials: 'same-origin'"), 'Explorer hydration recovery must preserve the authenticated session.');
$assert(str_contains($entrypoint, "url.searchParams.set('depth', 'all')"), 'Explorer hydration recovery must request complete projections.');

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$assert(str_contains($view, 'type="application/json"'), 'Architecture payload must be embedded as non-executable JSON.');
$assert(str_contains($view, 'data-architecture-stage'), 'Architecture graph stage missing.');
$assert(str_contains($view, 'JSON_INVALID_UTF8_SUBSTITUTE'), 'Architecture payload serialization must survive malformed UTF-8 metadata.');

$navigation = $read('symfony/src/Web/Navigation/NavigationBuilder.php');
$assert(str_contains($navigation, "'path' => 'cos/architecture'"), 'Architecture Explorer must be discoverable from canonical navigation.');

echo "Visualization V0.3/V0.5.1 Symfony delivery boundary passed.\n";
