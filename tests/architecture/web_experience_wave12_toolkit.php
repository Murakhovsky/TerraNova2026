<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/symfony/src/Web/Experience/Adapter/BrowserAdapter.php';

$requiredPackages = [
    'symfony/form',
    'symfony/translation',
    'symfony/ux-autocomplete',
    'symfony/ux-chartjs',
    'symfony/ux-dropzone',
    'symfony/ux-icons',
    'symfony/ux-map',
    'symfony/ux-toolkit',
    'symfony/ux-translator',
];

$composer = json_decode(
    (string) file_get_contents($root . '/symfony/composer.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

foreach ($requiredPackages as $package) {
    if (!isset($composer['require'][$package])) {
        throw new RuntimeException('Wave 12.2 Composer dependency is missing: ' . $package);
    }
}

$bundles = (string) file_get_contents($root . '/symfony/config/bundles.php');
foreach ([
    'AutocompleteBundle::class',
    'ChartjsBundle::class',
    'DropzoneBundle::class',
    'UXIconsBundle::class',
    'UXMapBundle::class',
    'UXToolkitBundle::class',
    'UxTranslatorBundle::class',
] as $bundle) {
    if (!str_contains($bundles, $bundle)) {
        throw new RuntimeException('Wave 12.2 bundle is not registered: ' . $bundle);
    }
}

$twig = (string) file_get_contents($root . '/symfony/config/packages/twig.yaml');
if (!str_contains($twig, 'bootstrap_5_layout.html.twig')) {
    throw new RuntimeException('Bootstrap 5 Symfony form theme is not configured.');
}

$icons = (string) file_get_contents($root . '/symfony/config/packages/ux_icons.yaml');
if (!str_contains($icons, 'enabled: false')) {
    throw new RuntimeException('Remote UX Icons fetching must be disabled in production architecture.');
}

if (!str_contains($icons, "assets/icons")) {
    throw new RuntimeException('Local UX Icons directory is not configured.');
}

$map = (string) file_get_contents($root . '/symfony/config/packages/ux_map.yaml');
if (!str_contains($map, "null://null")) {
    throw new RuntimeException('UX Map must remain provider-neutral until a renderer bridge is selected.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes/ux_autocomplete.yaml');
if (!str_contains($routes, '@AutocompleteBundle/config/routes.php')) {
    throw new RuntimeException('Autocomplete route resource is not registered.');
}

$controllers = json_decode(
    (string) file_get_contents($root . '/symfony/assets/controllers.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

foreach ([
    '@symfony/ux-autocomplete' => 'autocomplete',
    '@symfony/ux-chartjs' => 'chart',
    '@symfony/ux-dropzone' => 'dropzone',
] as $package => $controller) {
    if (($controllers['controllers'][$package][$controller]['enabled'] ?? false) !== true) {
        throw new RuntimeException(sprintf('UX controller is not enabled: %s/%s', $package, $controller));
    }
}

$expectedAdapters = [
    [\App\Web\Experience\Adapter\BrowserAdapter::Tabulator, 'tabulator-tables', 'adapters--tabulator'],
    [\App\Web\Experience\Adapter\BrowserAdapter::FullCalendar, 'fullcalendar', 'adapters--calendar'],
    [\App\Web\Experience\Adapter\BrowserAdapter::Sortable, 'sortablejs', 'adapters--sortable'],
    [\App\Web\Experience\Adapter\BrowserAdapter::Flatpickr, 'flatpickr', 'adapters--flatpickr'],
    [\App\Web\Experience\Adapter\BrowserAdapter::Cytoscape, 'cytoscape', 'adapters--cytoscape'],
];

foreach ($expectedAdapters as [$adapter, $module, $controller]) {
    if ($adapter->moduleSpecifier() !== $module) {
        throw new RuntimeException('Browser adapter module specifier drifted: ' . $adapter->value);
    }

    if ($adapter->stimulusController() !== $controller) {
        throw new RuntimeException('Browser adapter Stimulus identifier drifted: ' . $adapter->value);
    }
}

$adapterFiles = [
    'tabulator_controller.js',
    'calendar_controller.js',
    'sortable_controller.js',
    'flatpickr_controller.js',
    'cytoscape_controller.js',
];

foreach ($adapterFiles as $file) {
    $path = $root . '/symfony/assets/controllers/adapters/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException('Browser adapter controller is missing: ' . $file);
    }

    $source = (string) file_get_contents($path);
    foreach (['fetch(', 'axios', '/api/', 'Domains\\', 'Application\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Browser adapter %s contains forbidden application/data access token: %s',
                $file,
                $forbidden,
            ));
        }
    }
}

$importmap = (string) file_get_contents($root . '/symfony/importmap.php');
foreach ([
    "'bootstrap'",
    "'bootstrap/dist/css/bootstrap.min.css'",
    "'chart.js'",
    "'tabulator-tables'",
    "'fullcalendar'",
    "'sortablejs'",
    "'flatpickr'",
    "'cytoscape'",
    "'tom-select'",
    "'@symfony/ux-translator'",
    "'intl-messageformat'",
] as $specifier) {
    if (!str_contains($importmap, $specifier)) {
        throw new RuntimeException('Browser import is missing from canonical ImportMap: ' . $specifier);
    }
}

if (str_contains($importmap, 'https://') || str_contains($importmap, 'http://')) {
    throw new RuntimeException('Canonical ImportMap must not contain runtime CDN URLs.');
}

$app = (string) file_get_contents($root . '/symfony/assets/app.js');
if (!str_contains($app, "import 'bootstrap';")) {
    throw new RuntimeException('Bootstrap browser runtime is not activated by app.js.');
}

$base = (string) file_get_contents($root . '/symfony/templates/base.html.twig');
if (!str_contains($base, "asset('vendor/bootstrap/dist/css/bootstrap.min.css')")) {
    throw new RuntimeException('Bootstrap stylesheet is not loaded by the canonical Twig layout.');
}

$translator = (string) file_get_contents($root . '/symfony/assets/translator.js');
if (!str_contains($translator, "createTranslator")) {
    throw new RuntimeException('Canonical UX Translator wrapper is missing.');
}

echo "Wave 12.2 UI toolkit foundation passed.\n";
