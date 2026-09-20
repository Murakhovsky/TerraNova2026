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

$contract = $read('app/Kernel/Visualization/Graph/GraphHealthAnalyzerInterface.php');
$implementation = $read('app/Infrastructure/Visualization/Architecture/ArchitectureGraphHealthAnalyzer.php');
$services = $read('symfony/config/services.yaml');
$controller = $read('symfony/src/Web/Visualization/ArchitectureExplorerController.php');
$routes = $read('symfony/config/routes.yaml');
$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
$smoke = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');

$assert(str_contains($contract, 'interface GraphHealthAnalyzerInterface'), 'Graph health Kernel contract is missing.');
$assert(str_contains($contract, 'public function analyze(Graph $graph): array;'), 'Graph health contract must remain graph-only and renderer-neutral.');
$assert(str_contains($implementation, 'implements GraphHealthAnalyzerInterface'), 'Infrastructure health analyzer must implement the Kernel contract.');
foreach (['empty_graph', 'self_loop', 'duplicate_semantic_edge', 'isolated_node', 'node_parent_cycle', 'group_parent_cycle', 'empty_group'] as $code) {
    $assert(str_contains($implementation, "'{$code}'"), 'Health analyzer is missing issue code: ' . $code);
}

$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphHealthAnalyzerInterface:'), 'Symfony composition must register graph health analyzer contract.');
$assert(str_contains($services, 'Infrastructure\\Visualization\\Architecture\\ArchitectureGraphHealthAnalyzer'), 'Graph health analyzer implementation must be composed in Symfony.');

$assert(str_contains($controller, 'GraphHealthAnalyzerInterface'), 'Web controller must depend on the Kernel health contract.');
$assert(str_contains($controller, 'public function health('), 'Manager health JSON endpoint is missing.');
$assert(str_contains($controller, 'GraphHealthAnalyzerInterface'), 'Controller must resolve graph health analyzer through its contract.');
$assert(str_contains($controller, "'health'=>\$this->health->analyze(\$graph)"), 'Health endpoint must return the health payload.');
$assert(!str_contains($controller, 'Infrastructure\\Visualization'), 'Web controller must not compile against Infrastructure health implementation.');

$assert(str_contains($routes, 'cos_web_architecture_health:'), 'Architecture health Symfony route is missing.');
$assert(str_contains($routes, 'ArchitectureExplorerController::health'), 'Architecture health route must target the Symfony health action.');
$assert(str_contains($view, 'Architecture graph health'), 'Architecture Explorer must render graph health state.');
$assert(str_contains($view, "cos/architecture/health"), 'Architecture Explorer must link to the JSON health surface.');

foreach ([
    'GraphHealthAnalyzerInterface',
    "'analyze_canonical_graph'",
    'health->analyze(',
    "name: 'cos:architecture:smoke'",
] as $marker) {
    $assert(str_contains($smoke, $marker), 'Runtime smoke must validate graph health integration: ' . $marker);
}

echo "Visualization V0.5.5 Architecture Health vertical-slice contract passed.\n";
