<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Interfaces\Web\Assets\ViteAssetManifest;

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/public/build/.vite/manifest.json';
$entries = [
    'analytics-workspace',
    'clients-workspace',
    'company-home',
    'cos-architecture-explorer',
    'cos-control-center',
    'cos-site',
    'diagnostics-methodology-studio',
    'portal-cabinet',
    'property-workspace',
    'public-surface',
    'sales-workspace',
    'terranova-catalog-api',
    'terranova-copy',
    'terranova-interface',
    'terranova-media-manager',
    'terranova-property-gallery',
    'terranova-spatial-admin',
    'spatial-viewer',
];

foreach ([$root . '/public/js', $root . '/public/css', $root . '/public/assets/js', $root . '/public/assets/css'] as $legacyDirectory) {
    if (is_dir($legacyDirectory)) throw new RuntimeException('Legacy browser source directory was restored: ' . $legacyDirectory);
}
if (!is_dir($root . '/frontend')) throw new RuntimeException('Frontend source must live only in the root frontend directory.');
foreach (glob($root . '/resources/*.{js,jsx,ts,tsx,css,scss,vue}', GLOB_BRACE) ?: [] as $legacySource) {
    throw new RuntimeException('Frontend source was restored under resources: ' . $legacySource);
}

$viewRoot = $root . '/app/Interfaces/Web/View';
$inlineAssetExceptions = [
    'app/Interfaces/Web/View/property/pdf.phtml',
];
$views = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS),
);
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') {
        continue;
    }

    $relativePath = str_replace('\\', '/', substr($view->getPathname(), strlen($root) + 1));
    if (in_array($relativePath, $inlineAssetExceptions, true)) {
        continue;
    }

    $source = (string) file_get_contents($view->getPathname());
    if (preg_match('/<style\b/i', $source) === 1) {
        throw new RuntimeException('Inline CSS is forbidden in ordinary Web views; move it to frontend feature ownership: ' . $relativePath);
    }

    // PHP expressions inside an HTML attribute contain their own `?>`, which a naive
    // `<script ...>` regex mistakes for the end of the opening tag. Replace template
    // expressions only for static tag inspection; the original view source is untouched.
    $scriptScanSource = preg_replace('/<\?php\b.*?\?>/s', 'PHP_EXPR', $source);
    if (!is_string($scriptScanSource)) {
        throw new RuntimeException('Unable to prepare Web view for script asset inspection: ' . $relativePath);
    }

    if (preg_match_all('/<script\b([^>]*)>/i', $scriptScanSource, $scripts, PREG_SET_ORDER)) {
        foreach ($scripts as $script) {
            if (preg_match('/\btype\s*=\s*["\']application\/(?:ld\+json|json)["\']/i', $script[1]) === 1) {
                continue;
            }
            if (preg_match('/\bsrc\s*=\s*["\'][^"\']+["\']/i', $script[1]) === 1) {
                continue;
            }
            throw new RuntimeException('Inline browser JavaScript is forbidden in ordinary Web views; move it to a Vite entrypoint: ' . $relativePath);
        }
    }
}

$assets = (new ViteAssetManifest($manifestPath))->assets($entries);
if (count($assets['scripts']) !== count($entries)) throw new RuntimeException('Not every frontend entrypoint is present in the Vite manifest.');
foreach (array_merge($assets['scripts'], $assets['styles']) as $url) {
    $path = $root . '/public/' . ltrim(parse_url($url, PHP_URL_PATH), '/');
    if (!is_file($path)) throw new RuntimeException('Manifest asset is missing: ' . $path);
}

$viteConfig = (string) file_get_contents($root . '/vite.config.js');
foreach (['terranova-club', 'terranova-home'] as $retiredEntrypoint) {
    if (str_contains($viteConfig, "'{$retiredEntrypoint}'")) {
        throw new RuntimeException('Retired legacy entrypoint returned to Vite input: ' . $retiredEntrypoint);
    }
}

echo "Frontend assets passed: canonical browser source is built through the Vite manifest, ordinary Web views remain asset-pure, and retired global entrypoints stay out of runtime.\n";
