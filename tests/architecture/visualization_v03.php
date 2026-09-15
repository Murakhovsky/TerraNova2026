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
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
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

$controller = $read('app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php');
$assert(!str_contains($controller, 'Infrastructure\\'), 'Web controller must not compile against Infrastructure implementations.');
$assert(str_contains($controller, "getShared('cosArchitectureGraphProvider')"), 'Explorer must consume the canonical architecture graph provider.');
$assert(str_contains($controller, "getShared('cosCytoscapeGraphMapper')"), 'Explorer must resolve the Cytoscape adapter from composition.');

$routes = $read('app/Interfaces/Web/Routing/VisualizationRoutes.php');
$assert(str_contains($routes, "'/cos/architecture'"), 'Architecture Explorer route missing.');
$module = $read('app/Interfaces/Web/Module.php');
$assert(str_contains($module, "setShared('cosCytoscapeGraphMapper'"), 'Cytoscape mapper DI registration missing.');
$assert(str_contains($module, 'VisualizationRoutes::register($router)'), 'Visualization routes are not registered.');

$vite = $read('vite.config.js');
$assert(str_contains($vite, "'cos-architecture-explorer'"), 'Architecture Explorer Vite entry missing.');
$client = $read('frontend/features/cos/architecture-explorer.js');
foreach (['system', 'runtime', 'domain', 'data-architecture-search', 'data-architecture-depth', 'Collapse neighbors'] as $marker) {
    $assert(str_contains($client, $marker), 'Explorer interaction marker missing: ' . $marker);
}
$assert(str_contains($client, 'cytoscape@3.34.3'), 'Cytoscape browser dependency must be version-pinned.');

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$assert(str_contains($view, 'type="application/json"'), 'Architecture payload must be embedded as non-executable JSON.');
$assert(str_contains($view, 'data-architecture-stage'), 'Architecture graph stage missing.');

$navigation = $read('app/Interfaces/Web/Navigation/FrontendNavigation.php');
$assert(str_contains($navigation, "'path' => 'cos/architecture'"), 'Architecture Explorer must be discoverable from COS navigation.');

echo "Visualization V0.3 architecture boundary passed.\n";
