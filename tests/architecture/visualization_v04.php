<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Kernel/Visualization/Graph/GraphProjectionRegistryInterface.php',
    'app/Infrastructure/Visualization/Architecture/ArchitectureProjectionDefinition.php',
    'app/Infrastructure/Visualization/Architecture/ArchitectureGraphProjection.php',
    'app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php',
    'symfony/config/services.yaml',
    'tests/unit/visualization_architecture_projections.php',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.4 file: ' . $path);
}

$kernelRegistry = $read('app/Kernel/Visualization/Graph/GraphProjectionRegistryInterface.php');
$assert(!str_contains($kernelRegistry, 'Architecture'), 'Kernel projection registry contract must remain architecture-agnostic.');
$assert(!str_contains(strtolower($kernelRegistry), 'cytoscape'), 'Kernel projection registry contract must remain renderer-independent.');

$registry = $read('app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php');
foreach (['system', 'runtime', 'domain', 'dependencies', 'events', 'actions', 'agents', 'integrations', 'code'] as $view) {
    $assert(str_contains($registry, "'{$view}'"), 'Missing canonical architecture projection: ' . $view);
}
$assert(str_contains($registry, 'implements GraphProjectionRegistryInterface'), 'Architecture projection registry must implement the Kernel contract.');

$controller = $read('symfony/src/Web/Visualization/ArchitectureExplorerController.php');
$assert(str_contains($controller, 'GraphProjectionRegistryInterface'), 'Explorer must depend on the Kernel projection registry contract.');
$assert(str_contains($controller, 'GraphProjectionRegistryInterface'), 'Explorer projection registry constructor dependency missing.');
$assert(str_contains($controller, "'views' => \$views"), 'Explorer must publish projected view payloads.');
$assert(!str_contains($controller, 'Phalcon\\'), 'Symfony Web controller must not depend on Phalcon.');

$services = $read('symfony/config/services.yaml');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphProjectionRegistryInterface:'), 'Symfony projection registry composition missing.');
$assert(str_contains($services, "factory: ['Infrastructure\\Visualization\\Architecture\\ArchitectureProjectionRegistry', 'defaults']"), 'Canonical projection registry factory missing.');

$client = $read('frontend/features/cos/architecture-explorer.js');
$assert(!str_contains($client, 'SYSTEM_TYPES'), 'System projection semantics leaked back into browser code.');
$assert(!str_contains($client, 'RUNTIME_TYPES'), 'Runtime projection semantics leaked back into browser code.');
$assert(str_contains($client, 'payload.views'), 'Browser must consume server-projected views.');
$assert(str_contains($client, 'cy.add(elements)'), 'Projection switching must replace the rendered graph.');

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$assert(str_contains($view, '$viewDescriptions'), 'Explorer must render projection controls from server descriptions.');
$assert(str_contains($view, 'data-architecture-mode'), 'Projection controls missing from Explorer.');

echo "Visualization V0.4 architecture boundary passed.\n";
