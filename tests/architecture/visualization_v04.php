<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$registry = $read('app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php');
foreach (['system', 'runtime', 'domain', 'dependencies', 'events', 'actions', 'agents', 'integrations', 'code'] as $view) {
    $assert(str_contains($registry, "'{$view}'"), 'Missing canonical architecture projection: ' . $view);
}
$assert(str_contains($registry, 'implements GraphProjectionRegistryInterface'), 'Architecture projection registry must implement the Kernel contract.');

$application = $read('symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
foreach ([
    'GraphProjectionRegistryInterface',
    'GraphMapperInterface',
    '$this->registry->project(',
    '$this->mapper->map(',
    '$payload[\'view\'] = [',
] as $marker) {
    $assert(str_contains($application, $marker), 'Application projection boundary incomplete: ' . $marker);
}
$assert(!str_contains($application, 'Infrastructure\\'), 'Application projection boundary must not compile against Infrastructure.');

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
$assert(str_contains($controller, 'GetArchitectureProjectionQuery'), 'Explorer must request projections through Application Query.');
$assert(!str_contains($controller, 'GraphProjectionRegistryInterface'), 'Projection registry must not leak into Web controller.');

$bootstrap = $read('symfony/config/services.yaml');
$assert(str_contains($bootstrap, 'Infrastructure\\Visualization\\Architecture\\ArchitectureProjectionRegistry:'), 'Projection registry Symfony composition missing.');
$assert(str_contains($bootstrap, 'Kernel\\Visualization\\Graph\\GraphProjectionRegistryInterface:'), 'Projection registry Kernel alias missing.');

$client = $read('symfony/assets/controllers/architecture_explorer_controller.js');
foreach (['loadProjection(', 'this.cy.add(this.elements)', 'endpointValue', "url.searchParams.set('view', mode)"] as $marker) {
    $assert(str_contains($client, $marker), 'Architecture island projection switching incomplete: ' . $marker);
}
foreach (['SYSTEM_TYPES', 'RUNTIME_TYPES'] as $forbidden) {
    $assert(!str_contains($client, $forbidden), 'Projection semantics leaked into browser code: ' . $forbidden);
}

$view = $read('symfony/templates/experience/system/architecture.html.twig');
foreach (['architecture.views', 'data-architecture-mode=', 'architecture.defaultView'] as $marker) {
    $assert(str_contains($view, $marker), 'Explorer projection controls incomplete: ' . $marker);
}

echo "Visualization V0.4/Wave 13 projection boundary passed.\n";
