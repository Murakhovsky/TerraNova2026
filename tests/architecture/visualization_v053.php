<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$controller = $read('symfony/src/Web/Visualization/ArchitectureExplorerController.php');
$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$smoke = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');
$deploy = $read('deploy/dev.sh');
$visualizationServices = $read('symfony/config/services.yaml');

foreach ([
    "'build_canonical_graph'",
    "'map_canonical_graph'",
    "'project_' . \$name",
    'failureDiagnostic(',
    'reportFailure(',
] as $marker) {
    $assert(str_contains($controller, $marker), 'Architecture Explorer diagnostic stage is missing: ' . $marker);
}

$assert(str_contains($controller, "'diagnostic' => \$diagnostic"), 'Graph JSON endpoint must expose diagnostic payload.');
$assert(str_contains($view, 'data-architecture-backend-diagnostic'), 'Architecture view must render backend diagnostic details for managers.');

foreach ([
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'CytoscapeGraphMapper',
    'projections->project(',
    'mapper->map(',
    'Architecture Graph runtime smoke passed',
    'Architecture Graph runtime smoke failed',
] as $marker) {
    $assert(str_contains($smoke, $marker), 'Runtime Architecture Graph smoke is missing contract marker: ' . $marker);
}

$assert(
    str_contains($visualizationServices, 'Kernel\\Visualization\\Graph\\GraphProjectionRegistryInterface:'),
    'Symfony composition must register the projection registry contract.',
);

$assert(
    str_contains($deploy, 'php bin/console cos:architecture:smoke'),
    'AWS deploy must execute the Architecture Graph smoke inside the deployed PHP container.',
);
$assert(
    str_contains($deploy, 'exit 30'),
    'Architecture Graph deployment smoke must fail deployment with a dedicated exit code.',
);

echo "Visualization V0.5.3 diagnostics, DI binding and live smoke contract passed.\n";
