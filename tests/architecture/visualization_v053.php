<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$smoke = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');
$deploy = $read('deploy/dev.sh');
$services = $read('symfony/config/services.yaml');

foreach ([
    "'build_canonical_graph'",
    "'analyze_canonical_graph'",
    "'map_canonical_graph'",
    "'project_' . $name",
    'failureDiagnostic(',
] as $marker) {
    $assert(str_contains($controller, $marker), 'Architecture Explorer diagnostic stage is missing: ' . $marker);
}
$assert(str_contains($controller, "'diagnostic' => $diagnostic"), 'Graph JSON endpoint must expose manager-only diagnostic payload.');
$assert(str_contains($view, 'data-architecture-backend-diagnostic'), 'Architecture view must render backend diagnostic details for managers.');

foreach ([
    'GraphProviderInterface', 'GraphProjectionRegistryInterface', 'GraphPayloadMapperInterface',
    'projections->project(', 'mapper->map(', 'Architecture Graph runtime smoke passed',
    'Architecture Graph runtime smoke failed',
] as $marker) {
    $assert(str_contains($smoke, $marker), 'Runtime Architecture Graph smoke is missing contract marker: ' . $marker);
}

$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphProjectionRegistryInterface:'), 'Symfony composition must register the projection registry.');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphPayloadMapperInterface:'), 'Symfony composition must register the graph mapper port.');

$assert(str_contains($deploy, 'php bin/console cos:architecture:smoke'), 'AWS deploy must execute the Architecture Graph smoke in Symfony.');
$assert(str_contains($deploy, 'exit 30'), 'Architecture Graph deployment smoke must fail deployment with a dedicated exit code.');

echo "Visualization V0.5.3 Symfony diagnostics and live smoke contract passed.\n";
