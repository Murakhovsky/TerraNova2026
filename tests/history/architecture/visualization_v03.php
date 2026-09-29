<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php',
    'symfony/config/routes.yaml',
    'symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php',
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/templates/experience/system/architecture.html.twig',
    'symfony/assets/controllers/architecture_explorer_controller.js',
    'symfony/assets/styles/domains/system-architecture.css',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.3/Wave 13 artifact: ' . $path);
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

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach (['QueryBusInterface', 'GetArchitectureOverviewQuery', 'GetArchitectureProjectionQuery', 'GetArchitectureHealthQuery'] as $marker) {
    $assert(str_contains($controller, $marker), 'Architecture Web controller missing QueryBus boundary: ' . $marker);
}
foreach (['PhtmlRenderer', 'NavigationBuilder', 'GraphProviderInterface', 'GraphMapperInterface', 'GraphProjectionRegistryInterface', 'Infrastructure\\'] as $forbidden) {
    $assert(!str_contains($controller, $forbidden), 'Architecture Web controller leaked retired/direct graph dependency: ' . $forbidden);
}

$application = $read('symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
foreach (['GraphProviderInterface', 'GraphMapperInterface', 'GraphProjectionRegistryInterface', 'GraphHealthAnalyzerInterface'] as $marker) {
    $assert(str_contains($application, $marker), 'Architecture Application service missing Kernel graph contract: ' . $marker);
}
foreach (['App\\Web\\', 'Infrastructure\\'] as $forbidden) {
    $assert(!str_contains($application, $forbidden), 'Architecture Application service leaked delivery/Infrastructure dependency: ' . $forbidden);
}

$routes = $read('symfony/config/routes.yaml');
foreach (['cos_web_architecture:', 'path: /cos/architecture', 'cos_web_architecture_graph:', 'cos_web_architecture_health:'] as $marker) {
    $assert(str_contains($routes, $marker), 'Architecture route missing: ' . $marker);
}
$services = $read('symfony/config/services.yaml');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphMapperInterface:'), 'Cytoscape mapper must remain composed behind the Kernel mapper contract.');

$client = $read('symfony/assets/controllers/architecture_explorer_controller.js');
foreach (['cytoscape@3.34.3', "credentials: 'same-origin'", 'loadProjection(', 'renderTypeFilters(', 'Collapse neighbors', 'X'] as $marker) {
    if ($marker === 'X') continue;
    $assert(str_contains($client, $marker), 'Architecture Stimulus island missing interaction/runtime marker: ' . $marker);
}
$assert(!str_contains($client, 'innerHTML'), 'Architecture island must use DOM APIs instead of HTML-string rendering.');

$view = $read('symfony/templates/experience/system/architecture.html.twig');
foreach ([
    "extends 'experience/workspace_shell.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'data-controller="architecture-explorer"',
    'data-architecture-explorer-endpoint-value="/cos/architecture/graph"',
    'data-architecture-explorer-default-view-value=',
    'data-architecture-explorer-target="stage"',
] as $marker) {
    $assert(str_contains($view, $marker), 'Architecture Twig shell incomplete: ' . $marker);
}
foreach (['type="application/json"', 'cos-architecture-data', 'tn-', 'style=', '<script'] as $forbidden) {
    $assert(!str_contains($view, $forbidden), 'Architecture Twig restored legacy/embedded runtime: ' . $forbidden);
}

$navigation = $read('symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php');
$assert(str_contains($navigation, "new NavigationContribution('architecture', 'Architecture', '/cos/architecture'"), 'Architecture Explorer must remain discoverable in canonical Shell navigation.');

echo "Visualization V0.3/Wave 13 architecture boundary passed.\n";
