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
    'symfony/src/Web/Experience/Quality/PageQualityScore.php',
    'symfony/src/Command/ExperienceInventoryCommand.php',
    'symfony/src/Command/ExperienceCoverageCommand.php',
    'resources/experience/pages/core/golden.yaml',
    'resources/experience/pages/sales/golden.yaml',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Experience V1 registry file missing: ' . $file);
    }
}

$inventory = (string) file_get_contents($root . '/symfony/src/Command/ExperienceInventoryCommand.php');
foreach (['cos:experience:inventory', '--strict', 'Page Contract', 'DISCOVERED'] as $marker) {
    if (!str_contains($inventory, $marker)) {
        throw new RuntimeException('Experience inventory contract missing marker: ' . $marker);
    }
}

$coverage = (string) file_get_contents($root . '/symfony/src/Command/ExperienceCoverageCommand.php');
foreach (['cos:experience:coverage', 'Experience V1 ready', 'By Domain'] as $marker) {
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
foreach (glob($root . '/resources/experience/pages/*/*.yaml') ?: [] as $file) {
    $contracts .= (string) file_get_contents($file);
}

foreach ([
    'cos_web_workspace_home',
    'cos_web_sales_dashboard',
    'cos_web_sales_today',
    'cos_web_sales_pipeline',
    'cos_web_sales_leads',
    'cos_web_sales_deal',
    'cos_web_property_map',
    'cos_web_diagnostic_methodology',
] as $routeName) {
    if (!str_contains($routes, $routeName . ':')) {
        throw new RuntimeException('Golden Experience route missing from Symfony router source: ' . $routeName);
    }
    if (!str_contains($contracts, 'name: ' . $routeName)) {
        throw new RuntimeException('Golden Experience route missing Page Contract: ' . $routeName);
    }
}

echo "Experience V1 registry contract OK\n";
