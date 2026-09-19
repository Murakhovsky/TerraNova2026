<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'bin/sales-monitoring.php',
    'deploy/sales-monitoring.cron.example',
    'bin/architecture-graph-smoke.php',
    'bin/import-sales-methodology-v02.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired standalone Phalcon runtime entrypoint restored: ' . $path);
}

$scheduler = $read('symfony/src/Scheduler/CosScheduleProvider.php');
$assert(
    str_contains($scheduler, "RecurringMessage::every(\n                '5 minutes'")
    && str_contains($scheduler, 'RunSalesAutomationCommand'),
    'Sales monitoring must remain owned by Symfony Scheduler.',
);

$architecture = $read('symfony/src/Command/ArchitectureGraphSmokeCommand.php');
foreach ([
    "name: 'cos:architecture:smoke'",
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    'CytoscapeGraphMapper',
] as $needle) {
    $assert(str_contains($architecture, $needle), 'Canonical Architecture Graph smoke is missing: ' . $needle);
}
$assert(!str_contains($architecture, 'Phalcon\\'), 'Canonical Architecture Graph smoke depends on Phalcon.');

$factory = $read('symfony/src/Infrastructure/Visualization/ArchitectureGraphProviderFactory.php');
$assert(str_contains($factory, "get('registry')"), 'Architecture provider must resolve the runtime registry lazily.');
$assert(str_contains($factory, 'FallbackArchitectureGraphProvider'), 'Architecture provider lost its structural fallback.');
$assert(!str_contains($factory, 'Phalcon\\'), 'Canonical Architecture Graph provider factory depends on Phalcon.');

$importer = $read('symfony/src/Command/ImportSalesMethodologyV02Command.php');
foreach ([
    "name: 'cos:diagnostic:sales-methodology:import-v02'",
    'regression-scenarios.json',
    'unset($facts[(string) $factId])',
    "'-missing'",
] as $needle) {
    $assert(str_contains($importer, $needle), 'Canonical methodology importer is missing: ' . $needle);
}
$assert(!str_contains($importer, 'Phalcon\\'), 'Canonical methodology importer depends on Phalcon.');

$deploy = $read('deploy/dev.sh');
$assert(str_contains($deploy, 'php bin/console cos:architecture:smoke'), 'Deployment must execute the Symfony Architecture Graph smoke.');
$assert(!str_contains($deploy, 'architecture-graph-smoke.php'), 'Deployment still references the retired Architecture Graph script.');

echo "Standalone Phalcon-DI script retirement boundary OK\n";
