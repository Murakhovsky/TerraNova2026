<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$required = [
    'app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php',
    'symfony/src/Web/Visualization/ArchitectureExplorerController.php',
    'symfony/config/routes.yaml',
    'app/Interfaces/Web/View/visualization/architecture.phtml',
    'frontend/entrypoints/cos-architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.js',
    'frontend/features/cos/architecture-explorer.css',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.3 file: ' . $path);
}

$kernelRoot = $root . '/app/Kernel/Visualization';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kernelRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = strtolower((string) file_get_contents($file->getPathname()));
    $assert(!str_contains($source, 'cytoscape'), 'Kernel Visualization must remain renderer-independent: ' . $file->getPathname());
}

$mapper = $read('app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php');
$assert(str_contains($mapper, 'namespace Infrastructure\\Visualization\\Cytoscape'), 'Cytoscape mapper must live in Infrastructure.');
$assert(str_contains($mapper, 'public function map(Graph $graph): array'), 'Cytoscape mapper must consume the canonical Graph.');

$controller = $read('symfony/src/Web/Visualization/ArchitectureExplorerController.php');
foreach (['GraphProviderInterface', 'GraphProjectionRegistryInterface', 'GraphHealthAnalyzerInterface', 'CytoscapeGraphMapper'] as $marker) {
    $assert(str_contains($controller, $marker), 'Symfony Explorer dependency is missing: ' . $marker);
}
$assert(!str_contains($controller, 'Phalcon\\'), 'Symfony Explorer must not depend on Phalcon.');

$routes = $read('symfony/config/routes.yaml');
$assert(str_contains($routes, 'cos_web_architecture:'), 'Architecture Explorer Symfony route missing.');
$module = $read('app/Interfaces/Web/Module.php');
$assert(!str_contains($module, 'VisualizationRoutes::register($router)'), 'Phalcon Web module restored Visualization route ownership.');
$assert(!is_file($root . '/app/Interfaces/Web/Routing/VisualizationRoutes.php'), 'Retired Phalcon VisualizationRoutes restored.');
$assert(!is_file($root . '/app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php'), 'Retired Phalcon Explorer restored.');

$services = $read('symfony/config/services.yaml');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphProviderInterface:'), 'Symfony Visualization composition is missing GraphProviderInterface.');
$assert(str_contains($services, 'Infrastructure\\Visualization\\Cytoscape\\CytoscapeGraphMapper:'), 'Symfony Visualization composition is missing Cytoscape mapper.');

$vite = $read('vite.config.js');
$assert(str_contains($vite, "'cos-architecture-explorer'"), 'Architecture Explorer Vite entry missing.');
$client = $read('frontend/features/cos/architecture-explorer.js');
foreach (['data-architecture-search', 'data-architecture-depth', 'data-architecture-fit', 'data-architecture-reset', 'Collapse neighbors'] as $marker) {
    $assert(str_contains($client, $marker), 'Explorer interaction marker missing: ' . $marker);
}
$assert(str_contains($client, 'cytoscape@3.34.3'), 'Cytoscape browser dependency must be version-pinned.');

$entrypoint = $read('frontend/entrypoints/cos-architecture-explorer.js');
$assert(str_contains($entrypoint, 'hydrateArchitecturePayload'), 'Explorer entrypoint must recover an unusable embedded payload.');
$assert(str_contains($entrypoint, "credentials: 'same-origin'"), 'Explorer hydration recovery must preserve the authenticated manager session.');
$assert(str_contains($entrypoint, "url.searchParams.set('depth', 'all')"), 'Explorer hydration recovery must request complete server projections.');
$assert(str_contains($entrypoint, "await import('../features/cos/architecture-explorer.js')"), 'Explorer feature must boot only after hydration recovery has run.');

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$assert(str_contains($view, 'type="application/json"'), 'Architecture payload must be embedded as non-executable JSON.');
$assert(str_contains($view, 'data-architecture-stage'), 'Architecture graph stage missing.');
$assert(str_contains($view, 'data-architecture-default-view='), 'Architecture shell must expose the server-selected default projection independently from JSON hydration.');
$assert(str_contains($view, 'JSON_INVALID_UTF8_SUBSTITUTE'), 'Architecture payload serialization must survive malformed UTF-8 metadata.');

$navigation = $read('app/Interfaces/Web/Navigation/FrontendNavigation.php');
$assert(str_contains($navigation, "'path' => 'cos/architecture'"), 'Architecture Explorer must be discoverable from COS navigation.');

echo "Visualization V0.3/V0.5.1 architecture boundary passed.\n";
