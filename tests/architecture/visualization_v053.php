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

$application = $read('symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
$view = $read('symfony/templates/experience/system/architecture.html.twig');
$smoke = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');
$deploy = $read('deploy/dev.sh');
$services = $read('symfony/config/services.yaml');

foreach (['$this->provider->provide()', '$this->registry->descriptions()', '$this->mapper->map(', '$this->health->analyze('] as $marker) {
    $assert(str_contains($application, $marker), 'Architecture Application orchestration missing: ' . $marker);
}
foreach (['failureDiagnostic(', "'diagnostic' => \$diagnostic", "error_log(sprintf('[COS Visualization]"] as $marker) {
    $assert(str_contains($controller, $marker), 'Architecture Web diagnostic boundary missing: ' . $marker);
}
$assert(str_contains($view, 'role="alert"'), 'Architecture Twig island must expose an accessible runtime error surface.');

foreach ([
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'CytoscapeGraphMapper',
    'projections->project(',
    'mapper->map(',
    'Architecture Graph runtime smoke passed',
    'Architecture Graph runtime smoke failed',
] as $marker) {
    $assert(str_contains($smoke, $marker), 'Runtime Architecture Graph smoke missing: ' . $marker);
}

$assert(str_contains($services, 'Infrastructure\\Visualization\\Architecture\\ArchitectureProjectionRegistry:'), 'Visualization composition must register projection registry.');
$assert(str_contains($services, "factory: ['Infrastructure\\Visualization\\Architecture\\ArchitectureProjectionRegistry', 'defaults']"), 'Projection registry must resolve through canonical defaults factory.');
$assert(str_contains($deploy, 'php bin/console cos:architecture:smoke'), 'Deploy must execute Architecture Graph smoke.');
$assert(str_contains($deploy, 'exit 30'), 'Architecture Graph deployment smoke must retain dedicated failure exit code.');

echo "Visualization V0.5.3/Wave 13 diagnostics and live-smoke contract passed.\n";
