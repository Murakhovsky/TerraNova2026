<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'symfony/src/Web/Experience/Registry/PageContract.php',
    'symfony/src/Web/Experience/Registry/PageContractId.php',
    'symfony/src/Web/Experience/Registry/PageContractLoader.php',
    'symfony/src/Web/Experience/Registry/PageContractValidator.php',
    'symfony/src/Web/Experience/Registry/CompiledPageContractRegistry.php',
    'symfony/src/Web/Experience/Registry/ExperienceRouteInventory.php',
    'symfony/src/Web/Experience/Registry/RouteExemption.php',
    'symfony/src/Web/Experience/Registry/RouteExemptionRegistry.php',
    'symfony/src/Web/Experience/Quality/PageQualityScore.php',
    'symfony/src/Command/ExperienceInventoryCommand.php',
    'symfony/src/Command/ExperienceCoverageCommand.php',
    'resources/experience/exemptions.yaml',
    'resources/experience/pages/core/golden.yaml',
    'resources/experience/pages/sales/golden.yaml',
    'resources/experience/pages/inventory.yaml',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Experience V1 registry file missing: ' . $file);
    }
}

$inventory = (string) file_get_contents($root . '/symfony/src/Command/ExperienceInventoryCommand.php');
foreach (['cos:experience:inventory', "addOption('strict'", 'Page Contract', 'EXEMPT', 'mismatched'] as $marker) {
    if (!str_contains($inventory, $marker)) {
        throw new RuntimeException('Experience inventory contract missing marker: ' . $marker);
    }
}

$coverage = (string) file_get_contents($root . '/symfony/src/Command/ExperienceCoverageCommand.php');
foreach (['cos:experience:coverage', 'Inventory coverage', 'Experience V1 ready', 'By Domain'] as $marker) {
    if (!str_contains($coverage, $marker)) {
        throw new RuntimeException('Experience coverage contract missing marker: ' . $marker);
    }
}

$validator = (string) file_get_contents($root . '/symfony/src/Web/Experience/Registry/PageContractValidator.php');
foreach (['PageArchetypeRegistry', 'PatternRegistry', 'requiredPatterns', 'priority'] as $marker) {
    if (!str_contains($validator, $marker)) {
        throw new RuntimeException('Page Contract validator missing canonical gate: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$contracts = '';
foreach (glob($root . '/resources/experience/pages/*.yaml') ?: [] as $file) {
    $contracts .= (string) file_get_contents($file);
}
foreach (glob($root . '/resources/experience/pages/*/*.yaml') ?: [] as $file) {
    $contracts .= (string) file_get_contents($file);
}
$exemptions = (string) file_get_contents($root . '/resources/experience/exemptions.yaml');

preg_match_all(
    '/^([A-Za-z0-9_]+):\n(?:.*\n)*?\s+path:\s+([^\n]+)\n(?:.*\n)*?\s+controller:\s+([^\n]+)\n(?:.*\n)*?\s+methods:\s+\[([^\]]+)\]/m',
    $routes,
    $matches,
    PREG_SET_ORDER,
);

$production = [];
foreach ($matches as $match) {
    $name = $match[1];
    $path = trim($match[2]);
    $controller = trim($match[3]);
    $methods = array_map('trim', explode(',', $match[4]));

    if (array_intersect($methods, ['GET', 'HEAD']) === []) {
        continue;
    }
    if (preg_match('#^/(api(?:/|$)|webhooks(?:/|$)|telemetry(?:/|$)|health(?:/|$)|dev(?:/|$)|_wdt(?:/|$)|_profiler(?:/|$))#', $path)) {
        continue;
    }
    if (preg_match('/\.(?:xml|txt|json)$/', $path)) {
        continue;
    }
    if (!str_starts_with($controller, 'App\\Web\\') && !str_starts_with($name, 'cos_web_') && $name !== 'cos_symfony_home') {
        continue;
    }

    $production[] = $name;
}

if (count($production) < 90) {
    throw new RuntimeException('Experience route inventory unexpectedly shrank below 90 production HTML routes.');
}

foreach ($production as $routeName) {
    $contracted = str_contains($contracts, 'name: ' . $routeName);
    $exempted = str_contains($exemptions, 'route: ' . $routeName);

    if ($contracted === $exempted) {
        throw new RuntimeException(sprintf(
            'Production HTML route must have exactly one Page Contract or exemption: %s',
            $routeName,
        ));
    }
}

echo sprintf("Experience V1 registry contract OK (%d production HTML routes covered)\n", count($production));
