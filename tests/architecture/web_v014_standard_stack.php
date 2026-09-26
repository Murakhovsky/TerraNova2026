<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('WEB V0.14 artifact is missing: ' . $path);
    }
    return (string) file_get_contents($full);
};

$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

$package = json_decode($read('package.json'), true, 512, JSON_THROW_ON_ERROR);
$dependencies = is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [];
$expected = [
    '@popperjs/core' => '2.11.8',
    'bootstrap' => '5.3.8',
    'bootstrap-icons' => '1.13.1',
    'chart.js' => '4.5.1',
    'flatpickr' => '4.6.13',
    'fullcalendar' => '7.1.0',
    'htmx.org' => '2.0.10',
    'sortablejs' => '1.15.7',
    'tabulator-tables' => '6.5.2',
    'temporal-polyfill' => '1.0.5',
];
foreach ($expected as $dependency => $version) {
    if (($dependencies[$dependency] ?? null) !== $version) {
        throw new RuntimeException("WEB V0.14 dependency must be pinned: {$dependency}@{$version}");
    }
}
foreach (['jquery', 'react', 'react-dom', 'vue', '@angular/core', 'alpinejs'] as $framework) {
    if (isset($dependencies[$framework]) || isset($package['devDependencies'][$framework])) {
        throw new RuntimeException('Unapproved global frontend framework entered the standard stack: ' . $framework);
    }
}

$lock = $read('package-lock.json');
foreach (['node_modules/bootstrap', 'node_modules/htmx.org', 'node_modules/tabulator-tables', 'node_modules/chart.js', 'node_modules/fullcalendar'] as $needle) {
    $contains($lock, $needle, 'package-lock.json is not synchronized with the standard stack');
}

$designSystem = $read('frontend/styles/design-system.css');
$contains($designSystem, '@layer tn-vendor, tn-reset, tn-tokens', 'Vendor CSS must have lower cascade ownership than COS tokens');

$vendorCss = $read('frontend/styles/vendor/standard-stack.css');
foreach ([
    'bootstrap/dist/css/bootstrap.min.css" layer(tn-vendor)',
    'bootstrap-icons/font/bootstrap-icons.min.css" layer(tn-vendor)',
    './bootstrap-bridge.css" layer(tn-components)',
] as $needle) {
    $contains($vendorCss, $needle, 'Standard vendor CSS composition is incomplete');
}
$notContains($vendorCss, 'https://', 'Standard stack must be bundled by Vite rather than loaded from a CDN');

$bridge = $read('frontend/styles/vendor/bootstrap-bridge.css');
foreach (['--tn-color-ink', '--tn-color-border', '--tn-color-focus', '.btn-primary', '.form-control', '.modal-content'] as $needle) {
    $contains($bridge, $needle, 'Bootstrap bridge must inherit the COS visual language');
}

$entrypoint = $read('frontend/entrypoints/cos-ui-runtime.js');
foreach (['standard-stack.css', 'initCosUiRuntime'] as $needle) {
    $contains($entrypoint, $needle, 'COS UI runtime entrypoint is incomplete');
}

$runtime = $read('frontend/core/cos-ui-runtime.js');
foreach ([
    "htmx.org/dist/htmx.esm.js",
    "from 'bootstrap'",
    'htmx:configRequest',
    'htmx:beforeRequest',
    'htmx:afterRequest',
    'htmx:afterSwap',
    'htmx:responseError',
    'csrf_token',
    'X-CSRF-Token',
    'resetFormState',
    'cos:request-error',
] as $needle) {
    $contains($runtime, $needle, 'Bootstrap/HTMX adapter contract is incomplete');
}

$production = $read('frontend/core/production.js');
$contains($production, 'export const resetFormState', 'HTMX and ordinary forms must share the production submit-state reset');

$libraries = [
    'frontend/core/libraries/data-grid.js' => ['tabulator-tables', 'import('],
    'frontend/core/libraries/charts.js' => ['chart.js', 'import('],
    'frontend/core/libraries/sortable.js' => ['sortablejs', 'import('],
    'frontend/core/libraries/date-picker.js' => ['flatpickr', 'import('],
    'frontend/core/libraries/calendar.js' => ['fullcalendar', 'temporal-polyfill/global', 'import('],
];
foreach ($libraries as $path => $needles) {
    $source = $read($path);
    foreach ($needles as $needle) {
        $contains($source, $needle, 'Feature library must remain behind a lazy adapter: ' . $path);
    }
}

foreach ([
    'frontend/entrypoints/public-surface.js',
    'frontend/entrypoints/terranova-interface.js',
] as $surface) {
    $source = $read($surface);
    foreach (['bootstrap', 'htmx', 'cos-ui-runtime'] as $forbidden) {
        $notContains($source, $forbidden, 'Base surface bundles must stay independent from the opt-in standard runtime: ' . $surface);
    }
}

$vite = $read('vite.config.js');
$contains($vite, "'cos-ui-runtime': resolve(import.meta.dirname, 'frontend/entrypoints/cos-ui-runtime.js')", 'Vite must own the standard UI runtime entrypoint');

$assetGate = $read('tests/architecture/frontend_assets.php');
$contains($assetGate, "'cos-ui-runtime'", 'Shared asset gate must validate the standard UI runtime bundle');

$cosIndex = $read('symfony/templates/experience/public/cos_landing.html.twig');
$contains($cosIndex, 'data-cos-public="cos-landing"', 'COS landing must use the canonical Symfony/Twig public runtime');
$notContains($cosIndex, 'window.COS_PAGE', 'COS landing must not restore inline browser JavaScript');

$docs = $read('docs/architecture/web-v0.14.md');
foreach (['server-first', 'Bootstrap 5.3.8', 'HTMX 2.0.10', 'Tabulator', 'Chart.js', 'SortableJS', 'Flatpickr', 'FullCalendar', 'Vue'] as $needle) {
    $contains($docs, $needle, 'WEB V0.14 architecture documentation is incomplete');
}

echo "WEB V0.14 frontend standard stack passed.\n";
