<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Dev/UiCatalogEntry.php',
    'symfony/src/Web/Experience/Dev/UiCatalogRegistry.php',
    'symfony/assets/controllers/ui_catalog_controller.js',
    'symfony/templates/experience/_ui_catalog_playground.html.twig',
    'symfony/src/Command/UiCatalogSmokeCommand.php',
    'docs/03-architecture/ui-catalog.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.22 UI Catalog artifact is missing: ' . $relative);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
foreach (['Domains\\', 'Doctrine\\', '/api/', 'fetch('] as $forbidden) {
    if (str_contains($registry, $forbidden)) {
        throw new RuntimeException('UI Catalog registry contains forbidden business/data dependency: ' . $forbidden);
    }
}

$componentFiles = glob($root . '/symfony/src/Web/Experience/Component/Cos*.php') ?: [];
$componentNames = array_map(
    static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
    $componentFiles,
);
sort($componentNames);

preg_match_all("/entry\\('([^']+)'/", $registry, $matches);
$registered = $matches[1] ?? [];
sort($registered);

if ($componentNames !== $registered) {
    $missing = array_values(array_diff($componentNames, $registered));
    $unknown = array_values(array_diff($registered, $componentNames));

    throw new RuntimeException(sprintf(
        'UI Catalog registry drifted. Missing: [%s]. Unknown: [%s].',
        implode(', ', $missing),
        implode(', ', $unknown),
    ));
}

if (count($registered) !== count(array_unique($registered))) {
    throw new RuntimeException('UI Catalog contains duplicate component registrations.');
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/DesignSystemCatalogController.php');
foreach (['UiCatalogRegistry $uiCatalog', "'uiCatalog' => [", "'groups' =>", "'categories' =>", "'stats' =>"] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Design System controller does not expose canonical UI Catalog registry: ' . $marker);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
if (!str_contains($catalog, "_ui_catalog_playground.html.twig")) {
    throw new RuntimeException('Canonical /dev/ui does not mount the component playground.');
}

$playground = (string) file_get_contents($root . '/symfony/templates/experience/_ui_catalog_playground.html.twig');
foreach ([
    'Internal component playground',
    'data-controller="ui-catalog"',
    'data-ui-catalog-target="query"',
    'data-ui-catalog-target="category"',
    'data-ui-catalog-target="entry"',
    'Foundation specimens',
    'Feedback specimens',
    'Form control specimens',
] as $marker) {
    if (!str_contains($playground, $marker)) {
        throw new RuntimeException('UI Catalog playground contract is incomplete: ' . $marker);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/ui_catalog_controller.js');
foreach (['fetch(', '/api/', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($stimulus, $forbidden)) {
        throw new RuntimeException('UI Catalog browser behavior must remain local-only: ' . $forbidden);
    }
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
if (!str_contains($security, "path: '^/dev(?:/|$)'") || !str_contains($security, 'roles: ROLE_MANAGER')) {
    throw new RuntimeException('UI Catalog must remain manager-only.');
}

$devController = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/DesignSystemCatalogController.php');
foreach (['X-Robots-Tag', 'noindex, nofollow', 'no-store, private'] as $marker) {
    if (!str_contains($devController, $marker)) {
        throw new RuntimeException('UI Catalog internal-only response contract is missing: ' . $marker);
    }
}

echo "Wave 12.22 UI Catalog passed.\n";
