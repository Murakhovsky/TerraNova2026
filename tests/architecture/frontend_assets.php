<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Interfaces\Web\Assets\ViteAssetManifest;

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/public/build/.vite/manifest.json';
$entries = [
    'cos-control-center',
    'cos-site',
    'diagnostics-methodology-studio',
    'terranova-catalog-api',
    'terranova-club',
    'terranova-copy',
    'terranova-home',
    'terranova-interface',
    'terranova-media-manager',
    'terranova-property-gallery',
    'terranova-spatial-admin',
    'spatial-viewer',
];

foreach ([$root . '/public/js', $root . '/public/css', $root . '/public/assets/js', $root . '/public/assets/css'] as $legacyDirectory) {
    if (is_dir($legacyDirectory)) {
        throw new RuntimeException('Legacy browser source directory was restored: ' . $legacyDirectory);
    }
}
if (!is_dir($root . '/frontend')) {
    throw new RuntimeException('Frontend source must live only in the root frontend directory.');
}
foreach (glob($root . '/resources/*.{js,jsx,ts,tsx,css,scss,vue}', GLOB_BRACE) ?: [] as $legacySource) {
    throw new RuntimeException('Frontend source was restored under resources: ' . $legacySource);
}

$assets = (new ViteAssetManifest($manifestPath))->assets($entries);
if (count($assets['scripts']) !== count($entries)) {
    throw new RuntimeException('Not every frontend entrypoint is present in the Vite manifest.');
}
foreach (array_merge($assets['scripts'], $assets['styles']) as $url) {
    $path = $root . '/public/' . ltrim(parse_url($url, PHP_URL_PATH), '/');
    if (!is_file($path)) throw new RuntimeException('Manifest asset is missing: ' . $path);
}

echo "Frontend assets passed: all browser source is built through the Vite manifest.\n";
