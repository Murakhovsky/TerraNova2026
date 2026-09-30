<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$contract = $read('app/Kernel/Visualization/Graph/GraphHealthAnalyzerInterface.php');
$implementation = $read('app/Infrastructure/Visualization/Architecture/ArchitectureGraphHealthAnalyzer.php');
$services = $read('symfony/config/services.yaml');
$application = $read('symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
$routes = $read('symfony/config/routes.yaml');
$view = $read('symfony/templates/experience/system/architecture.html.twig');
$smoke = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');

$assert(str_contains($contract, 'interface GraphHealthAnalyzerInterface'), 'Graph health Kernel contract missing.');
$assert(str_contains($contract, 'public function analyze(Graph $graph): array;'), 'Graph health contract must remain renderer-neutral.');
$assert(str_contains($implementation, 'implements GraphHealthAnalyzerInterface'), 'Infrastructure health analyzer must implement Kernel contract.');
foreach (['empty_graph', 'self_loop', 'duplicate_semantic_edge', 'isolated_node', 'node_parent_cycle', 'group_parent_cycle', 'empty_group'] as $code) {
    $assert(str_contains($implementation, "'{$code}'"), 'Health analyzer missing issue code: ' . $code);
}

$assert(str_contains($services, 'Infrastructure\\Visualization\\Architecture\\ArchitectureGraphHealthAnalyzer:'), 'Graph health analyzer Symfony composition missing.');
$assert(str_contains($services, 'Kernel\\Visualization\\Graph\\GraphHealthAnalyzerInterface:'), 'Graph health Kernel alias missing.');
foreach (['GraphHealthAnalyzerInterface', '$this->health->analyze('] as $marker) {
    $assert(str_contains($application, $marker), 'Application graph-health boundary missing: ' . $marker);
}
foreach (['GetArchitectureHealthQuery', 'public function health('] as $marker) {
    $assert(str_contains($controller, $marker), 'Manager health endpoint missing Application Query boundary: ' . $marker);
}
$assert(str_contains($routes, 'cos_web_architecture_health:'), 'Architecture health route missing.');
$assert(str_contains($routes, 'ArchitecturePageController::health'), 'Architecture health route must target Symfony action.');
$assert(str_contains($view, 'Graph Health'), 'Architecture Twig surface must render graph health state.');
$assert(str_contains($view, '/cos/architecture/health'), 'Architecture Twig surface must link JSON health.');

foreach (['GraphHealthAnalyzerInterface', "'analyze_canonical_graph'", 'health->analyze(', "name: 'cos:architecture:smoke'"] as $marker) {
    $assert(str_contains($smoke, $marker), 'Runtime smoke must validate graph health integration: ' . $marker);
}

echo "Visualization V0.5.5/Wave 13 Architecture Health contract passed.\n";
