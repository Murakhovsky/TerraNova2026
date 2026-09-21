<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/public/manifest.webmanifest',
    'symfony/public/sw.js',
    'symfony/public/offline.html',
    'symfony/public/icons/cos-192.svg',
    'symfony/public/icons/cos-512.svg',
    'symfony/public/icons/cos-maskable.svg',
    'symfony/assets/pwa_runtime.js',
    'symfony/src/Command/PwaFoundationSmokeCommand.php',
    'docs/03-architecture/pwa-foundation.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.17 PWA artifact is missing: ' . $relative);
    }
}

$manifest = json_decode((string) file_get_contents($root . '/symfony/public/manifest.webmanifest'), true);
if (!is_array($manifest)) {
    throw new RuntimeException('PWA manifest must be valid JSON.');
}

foreach ([
    'name' => 'COS — Company Operating System',
    'short_name' => 'COS',
    'start_url' => '/admin',
    'scope' => '/',
    'display' => 'standalone',
] as $key => $expected) {
    if (($manifest[$key] ?? null) !== $expected) {
        throw new RuntimeException('PWA manifest contract is invalid: ' . $key);
    }
}

$icons = $manifest['icons'] ?? null;
if (!is_array($icons) || count($icons) < 3) {
    throw new RuntimeException('PWA manifest requires canonical installability icons.');
}

$base = (string) file_get_contents($root . '/symfony/templates/base.html.twig');
foreach ([
    'rel="manifest" href="/manifest.webmanifest"',
    'name="theme-color"',
    'name="mobile-web-app-capable"',
    'name="apple-mobile-web-app-capable"',
] as $marker) {
    if (!str_contains($base, $marker)) {
        throw new RuntimeException('Base layout PWA metadata is missing: ' . $marker);
    }
}

$app = (string) file_get_contents($root . '/symfony/assets/app.js');
if (!str_contains($app, "import './pwa_runtime.js';")) {
    throw new RuntimeException('Canonical PWA browser runtime is not registered.');
}

$runtime = (string) file_get_contents($root . '/symfony/assets/pwa_runtime.js');
foreach ([
    "register('/sw.js'",
    "updateViaCache: 'none'",
    'cos:pwa-update-ready',
    'cos:pwa-apply-update',
    "postMessage({ type: 'SKIP_WAITING' })",
    'controllerchange',
] as $marker) {
    if (!str_contains($runtime, $marker)) {
        throw new RuntimeException('PWA update runtime contract is missing: ' . $marker);
    }
}

$worker = (string) file_get_contents($root . '/symfony/public/sw.js');
foreach ([
    "request.method !== 'GET'",
    "request.mode !== 'navigate'",
    'fetch(request).catch',
    'cache.match(OFFLINE_URL)',
    "event.data?.type === 'SKIP_WAITING'",
] as $marker) {
    if (!str_contains($worker, $marker)) {
        throw new RuntimeException('PWA service worker contract is missing: ' . $marker);
    }
}

foreach ([
    'indexedDB',
    'localStorage',
    'sessionStorage',
    'BackgroundSync',
    'sync.register',
    '/api/',
    'POST',
    'PUT',
    'PATCH',
    'DELETE',
] as $forbidden) {
    if (str_contains($worker, $forbidden)) {
        throw new RuntimeException('PWA worker must not own offline business state or mutations: ' . $forbidden);
    }
}

$offline = (string) file_get_contents($root . '/symfony/public/offline.html');
foreach (['data-cos-offline-fallback', 'online-first'] as $marker) {
    if (!str_contains($offline, $marker)) {
        throw new RuntimeException('Static offline fallback contract is missing: ' . $marker);
    }
}

$nginx = (string) file_get_contents($root . '/docker/symfony/nginx/default.conf');
foreach ([
    'location = /sw.js',
    'Service-Worker-Allowed "/"',
    'no-cache, no-store, must-revalidate',
    'location = /manifest.webmanifest',
    'application/manifest+json',
    'location = /offline.html',
    'location ^~ /icons/',
] as $marker) {
    if (!str_contains($nginx, $marker)) {
        throw new RuntimeException('Nginx PWA delivery contract is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/PwaFoundationSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:pwa:smoke'")) {
    throw new RuntimeException('PWA runtime smoke is missing.');
}

echo "Wave 12.17 PWA Foundation passed.\n";
