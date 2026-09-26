<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) {
        throw new RuntimeException('Wave 13 final-audit artifact is missing: ' . $path);
    }

    $content = file_get_contents($full);
    if ($content === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }

    return $content;
};

$tracker = $read('docs/03-architecture/wave13-migration-tracker.md');
for ($unit = 1; $unit <= 47; $unit++) {
    $id = sprintf('VR-%03d', $unit);
    if (preg_match('/\\| '.preg_quote($id, '/').' \\|[^\\n]*\\| DONE \\|/', $tracker) !== 1) {
        throw new RuntimeException('Wave 13 tracker unit is not DONE: ' . $id);
    }
}
foreach (['status: closed', '## Wave 13 — фінальне закриття'] as $marker) {
    if (!str_contains($tracker, $marker)) {
        throw new RuntimeException('Wave 13 closure metadata is incomplete: ' . $marker);
    }
}

$allowedPagePhtml = [
    'auth/login.phtml',
    'auth/register.phtml',
    'diagnostic_report/show.phtml',
    'error/failure.phtml',
    'property/pdf.phtml',
    'property/presentation.phtml',
    'spatial/edit.phtml',
    'spatial/scene.phtml',
];

$viewRoot = $root . '/app/Interfaces/Web/View';
$actualPagePhtml = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
    if (
        $relative === 'index.phtml'
        || str_starts_with($relative, 'components/')
        || str_starts_with($relative, 'shared/')
    ) {
        continue;
    }

    $actualPagePhtml[] = $relative;
}

sort($actualPagePhtml);
$expectedPagePhtml = $allowedPagePhtml;
sort($expectedPagePhtml);
if ($actualPagePhtml !== $expectedPagePhtml) {
    throw new RuntimeException(
        "Wave 13 page-level PHTML whitelist drift.\nExpected: "
        . implode(', ', $expectedPagePhtml)
        . "\nActual: "
        . implode(', ', $actualPagePhtml),
    );
}

$contracts = [
    'auth/login.phtml' => ['name="email"', 'name="password"'],
    'auth/register.phtml' => ['name="password_repeat"', 'minlength="8"'],
    'diagnostic_report/show.phtml' => ['tn-diagnostic-report', 'overallHealth', 'measurements'],
    'error/failure.phtml' => ['tn-failure', 'data-failure-code'],
    'property/pdf.phtml' => ['<!DOCTYPE html>', '@page', 'partner--sheet'],
    'property/presentation.phtml' => ['tn-presentation-hero', 'data-copy-value'],
    'spatial/edit.phtml' => ['tn-spatial-workbench', 'data-spatial-upload'],
    'spatial/scene.phtml' => ['tn-spatial-public', "partial('shared/spatial_viewer'"],
];
foreach ($contracts as $path => $markers) {
    $source = $read('app/Interfaces/Web/View/' . $path);
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('Specialized/compatibility contract is incomplete: ' . $path . ' -> ' . $marker);
        }
    }
}

foreach ([
    'app/Interfaces/Web/View/index/index.phtml',
    'app/Interfaces/Web/View/home/canonical.phtml',
    'app/Interfaces/Web/View/company_os/index.phtml',
    'app/Interfaces/Web/View/company_os/domain.phtml',
    'app/Interfaces/Web/View/company_os/not_found.phtml',
    'app/Interfaces/Web/View/page/show.phtml',
    'app/Interfaces/Web/View/blog/index.phtml',
    'app/Interfaces/Web/View/blog/show.phtml',
    'app/Interfaces/Web/View/blog/landing.phtml',
    'app/Interfaces/Web/View/property/catalog.phtml',
    'app/Interfaces/Web/View/property/show.phtml',
    'app/Interfaces/Web/View/property/seo.phtml',
    'app/Interfaces/Web/View/property/favour.phtml',
    'app/Interfaces/Web/View/property/submit.phtml',
    'app/Interfaces/Web/View/methodology_studio/index.phtml',
    'app/Interfaces/Web/View/cabinet/index.phtml',
    'app/Interfaces/Web/View/cabinet/submission.phtml',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('Retired Wave 13 page renderer restored: ' . $retired);
    }
}

$entryRoot = $root . '/frontend/entrypoints';
$actualEntries = [];
foreach (new DirectoryIterator($entryRoot) as $file) {
    if ($file->isFile() && $file->getExtension() === 'js') {
        $actualEntries[] = $file->getFilename();
    }
}
sort($actualEntries);
$expectedEntries = [
    'cos-ui-runtime.js',
    'public-surface.js',
    'terranova-copy.js',
    'terranova-interface.js',
    'terranova-media-manager.js',
    'terranova-spatial-admin.js',
];
sort($expectedEntries);
if ($actualEntries !== $expectedEntries) {
    throw new RuntimeException(
        "Wave 13 Vite source entrypoint whitelist drift.\nExpected: "
        . implode(', ', $expectedEntries)
        . "\nActual: "
        . implode(', ', $actualEntries),
    );
}

$vite = $read('vite.config.js');
foreach ([
    "'cos-site':",
    "'cos-control-center':",
    "'diagnostics-methodology-studio':",
    "'sales-workspace':",
    "'clients-workspace':",
    "'property-workspace':",
    "'portal-cabinet':",
    "'terranova-catalog-api':",
    "'terranova-property-gallery':",
    "'terranova-home':",
    "'terranova-club':",
] as $retiredEntry) {
    if (str_contains($vite, $retiredEntry)) {
        throw new RuntimeException('Retired Vite source entrypoint returned: ' . $retiredEntry);
    }
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /admin',
    'path: /sales/today',
    'path: /client-case',
    'path: /property/manage',
    'path: /cos/control-center',
    'path: /cabinet',
    'path: /property/catalog',
    'path: /terra-nova',
    'path: /blog',
    'path: /cos/{lang}',
    'path: /cos/{lang}/domains/{slug}',
] as $routeMarker) {
    if (!str_contains($routes, $routeMarker)) {
        throw new RuntimeException('Wave 13 canonical route ownership is missing: ' . $routeMarker);
    }
}

echo "Wave 13 final audit passed: VR-001…047 closed, page-level PHTML and Vite source ownership classified.\n";
