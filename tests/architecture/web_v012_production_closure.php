<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException('WEB V0.12 artifact is missing: ' . $path);
    return (string) file_get_contents($full);
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};

foreach (['app/Domains/Frontend', 'app/Domains/Public', 'app/Domains/Portal'] as $forbiddenDomain) {
    if (is_dir($root . '/' . $forbiddenDomain)) {
        throw new RuntimeException('Frontend surfaces must remain Interface/Presentation concerns: ' . $forbiddenDomain);
    }
}

foreach ([
    'app/Interfaces/Web/Controller/Concerns/RendersFrontendFailure.php',
    'app/Interfaces/Web/View/error/failure.phtml',
    'frontend/core/production.js',
    'frontend/styles/production.css',
    'docs/architecture/web-v0.12.md',
] as $path) {
    $read($path);
}

foreach ([
    'frontend/entrypoints/terranova-club.js',
    'frontend/entrypoints/terranova-home.js',
    'frontend/styles/terranova-club.css',
    'frontend/styles/terranova-home.css',
] as $retiredPath) {
    if (is_file($root . '/' . $retiredPath)) {
        throw new RuntimeException('Retired legacy runtime source returned after WEB V0.12: ' . $retiredPath);
    }
}

$designSystem = $read('frontend/styles/design-system.css');
foreach (['tokens.css', 'foundation.css', 'components.css', 'patterns.css', 'production.css'] as $needle) {
    $contains($designSystem, $needle, 'Design system is missing a production layer dependency.');
}
if (!(strpos($designSystem, 'tokens.css') < strpos($designSystem, 'foundation.css')
    && strpos($designSystem, 'foundation.css') < strpos($designSystem, 'components.css')
    && strpos($designSystem, 'components.css') < strpos($designSystem, 'patterns.css')
    && strpos($designSystem, 'patterns.css') < strpos($designSystem, 'production.css'))) {
    throw new RuntimeException('Canonical CSS order must remain tokens -> foundation -> components -> patterns -> production.');
}

$productionCss = $read('frontend/styles/production.css');
foreach ([
    'min-height: 44px',
    '@media (max-width: 650px)',
    '@media (min-width: 651px) and (max-width: 1050px)',
    '@media (min-width: 1051px) and (max-width: 1439px)',
    '@media (min-width: 1440px)',
    '@media (prefers-reduced-motion: reduce)',
] as $needle) {
    $contains($productionCss, $needle, 'Production responsive/accessibility contract is incomplete.');
}

$productionJs = $read('frontend/core/production.js');
foreach (['event.preventDefault()', 'data-submitting', "aria-busy", "window.addEventListener('pageshow'", 'is-pending'] as $needle) {
    $contains($productionJs, $needle, 'Cross-surface submit guard is incomplete.');
}

foreach ([
    'frontend/entrypoints/public-surface.js',
    'frontend/entrypoints/portal-cabinet.js',
    'frontend/entrypoints/terranova-interface.js',
] as $entrypoint) {
    $source = $read($entrypoint);
    $contains($source, "../core/production.js", 'Canonical surface must load the production UX guard.');
    $contains($source, 'initProductionUX()', 'Canonical surface must initialize the production UX guard.');
}

$publicSurface = $read('frontend/features/public/surface.js');
foreach (["button.addEventListener('click'", "classList.toggle('is-open'", "event.key === 'Escape'", "aria-expanded"] as $needle) {
    $contains($publicSurface, $needle, 'Public mobile navigation regression returned.');
}

$publicInteractions = $read('frontend/features/public/interactions.js');
foreach (["/api/property/favourites", 'fetchFavourites', 'campaignFromLocation', 'aria-pressed'] as $needle) {
    $contains($publicInteractions, $needle, 'Public interactions are missing server-owned UI state.');
}

$apiController = $read('app/Interfaces/Web/Controller/ApiController.php');
foreach (['FAVOURITES_SESSION_KEY', 'function favouritesAction', "getShared('session')", 'FAVOURITES_LIMIT'] as $needle) {
    $contains($apiController, $needle, 'Favourites must be server-session owned.');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/frontend'));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') continue;
    $source = (string) file_get_contents($file->getPathname());
    foreach (['localStorage', 'sessionStorage'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('Browser persistence cannot be frontend truth after WEB V0.12: ' . $file->getPathname() . ' -> ' . $forbidden);
        }
    }
}

$failureTrait = $read('app/Interfaces/Web/Controller/Concerns/RendersFrontendFailure.php');
foreach (['403 =>', '404 =>', '422 =>', '500 =>', '503 =>', 'request_id', 'renderFrontendException', "pick('error/failure')"] as $needle) {
    $contains($failureTrait, $needle, 'Frontend failure contract is incomplete.');
}
$failureView = $read('app/Interfaces/Web/View/error/failure.phtml');
foreach (['failureRequestId', 'failureActionUrl', 'tn-failure__request'] as $needle) {
    $contains($failureView, $needle, 'Failure view must expose a safe recovery path and request id.');
}

foreach (['app/Interfaces/Web/Controller/ControllerBase.php', 'app/Interfaces/Web/Controller/WebController.php'] as $baseController) {
    $source = $read($baseController);
    $contains($source, 'RendersFrontendFailure', 'Web controller base must use the canonical failure contract.');
    $contains($source, 'renderFrontendFailure(403', 'Permission denial must render an explicit failure state.');
}

$webControllers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/Controller'));
foreach ($webControllers as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('/view->pageStatus\s*=\s*\$[A-Za-z_][A-Za-z0-9_]*->getMessage\s*\(/', $source)) {
        throw new RuntimeException('Raw exception message is still rendered into Web UI: ' . $file->getPathname());
    }
}

$bootstrap = $read('app/bootstrap_web.php');
foreach (['request_id', 'Cache-Control: no-store', 'Код звернення', 'http_response_code(500)'] as $needle) {
    $contains($bootstrap, $needle, 'Bootstrap 500 fallback is incomplete.');
}
$notContains($bootstrap, "echo 'Internal Server Error'", 'Bare bootstrap error response must not return.');

$manifestPath = $root . '/public/build/.vite/manifest.json';
if (!is_file($manifestPath)) throw new RuntimeException('Vite manifest is required for production budget checks.');
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$budgets = [
    'frontend/entrypoints/public-surface.js' => ['js' => 160 * 1024, 'css' => 180 * 1024],
    'frontend/entrypoints/portal-cabinet.js' => ['js' => 96 * 1024, 'css' => 96 * 1024],
    'frontend/entrypoints/terranova-interface.js' => ['js' => 128 * 1024, 'css' => 128 * 1024],
];
foreach ($budgets as $entry => $budget) {
    if (!isset($manifest[$entry]) || !is_array($manifest[$entry])) {
        throw new RuntimeException('Canonical Vite entry is missing from manifest: ' . $entry);
    }
    $record = $manifest[$entry];
    $jsFile = isset($record['file']) ? $root . '/public/build/' . $record['file'] : null;
    if ($jsFile && is_file($jsFile) && filesize($jsFile) > $budget['js']) {
        throw new RuntimeException('Canonical JS budget exceeded: ' . $entry);
    }
    $cssBytes = 0;
    foreach (($record['css'] ?? []) as $cssFile) {
        $full = $root . '/public/build/' . $cssFile;
        if (is_file($full)) $cssBytes += filesize($full);
    }
    if ($cssBytes > $budget['css']) {
        throw new RuntimeException('Canonical CSS budget exceeded: ' . $entry);
    }
}
$manifestSource = (string) file_get_contents($manifestPath);
foreach (['terranova-club.js', 'terranova-home.js'] as $retiredEntry) {
    $notContains($manifestSource, $retiredEntry, 'Retired entrypoint returned to production manifest.');
}

$docs = $read('docs/architecture/web-v0.12.md');
foreach (['403', '404', '422', '500', '503', '650', '1050', '1440', 'WCAG 2.2 AA', 'performance budget', 'router debt'] as $needle) {
    $contains($docs, $needle, 'WEB V0.12 production closure documentation is incomplete.');
}

echo "WEB V0.12 frontend production closure passed.\n";
