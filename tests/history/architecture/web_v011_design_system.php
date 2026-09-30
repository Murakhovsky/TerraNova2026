<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException('WEB V0.11 artifact is missing: ' . $path);
    return (string) file_get_contents($full);
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};

foreach ([
    'frontend/styles/interface.css',
    'frontend/styles/terranova-club.css',
    'frontend/styles/terranova-home.css',
] as $retiredSource) {
    if (is_file($root . '/' . $retiredSource)) {
        throw new RuntimeException('Retired global frontend source restored: ' . $retiredSource);
    }
}

foreach (['app/Domains/Frontend', 'app/Domains/Public', 'app/Domains/Portal'] as $forbiddenDomain) {
    if (is_dir($root . '/' . $forbiddenDomain)) {
        throw new RuntimeException('Frontend surfaces must remain Interface/Presentation concerns: ' . $forbiddenDomain);
    }
}

foreach ([
    'frontend/styles/design-system.css',
    'frontend/styles/layouts/public.css',
    'frontend/styles/layouts/workspace.css',
    'frontend/features/public/interactions.js',
    'docs/architecture/web-v0.11.md',
    'docs/architecture/frontend-legacy-audit.md',
] as $path) {
    $read($path);
}

$designSystem = $read('frontend/styles/design-system.css');
$positions = [];
foreach (['tokens.css', 'foundation.css', 'components.css', 'patterns.css'] as $needle) {
    $positions[$needle] = strpos($designSystem, $needle);
    if ($positions[$needle] === false) throw new RuntimeException('Design-system load order is missing: ' . $needle);
}
if (!($positions['tokens.css'] < $positions['foundation.css']
    && $positions['foundation.css'] < $positions['components.css']
    && $positions['components.css'] < $positions['patterns.css'])) {
    throw new RuntimeException('Design-system load order must be tokens -> foundation -> components -> patterns.');
}

$layout = $read('app/Interfaces/Web/View/index.phtml');
foreach ([
    "'workspace' => 'terranova-interface'",
    "default => 'public-surface'",
    'data-interface-surface="<?php echo $escape($interfaceSurface); ?>"',
    'array_unique',
] as $needle) {
    $contains($layout, $needle, 'Root layout is missing canonical surface asset ownership.');
}
$notContains($layout, "array_merge(['terranova-club', 'terranova-interface']", 'Root layout must not globally load the historical Public/Workspace bundles.');
$notContains($layout, "'terranova-club'", 'Root layout must not reference retired terranova-club entrypoint.');
$notContains($layout, "'terranova-home'", 'Root layout must not reference retired terranova-home entrypoint.');

$publicEntry = $read('frontend/entrypoints/public-surface.js');
foreach (["../styles/design-system.css", "../styles/layouts/public.css", "../features/public/surface.css", 'initPublicInteractions'] as $needle) {
    $contains($publicEntry, $needle, 'Public entrypoint is missing canonical design-system/surface ownership.');
}
$workspaceEntry = $read('frontend/entrypoints/terranova-interface.js');
foreach (["../styles/design-system.css", "../styles/layouts/workspace.css", 'initWorkspaceShell'] as $needle) {
    $contains($workspaceEntry, $needle, 'Workspace entrypoint is missing canonical design-system/surface ownership.');
}
foreach (['interface.css', 'workspace-mobile.css', 'terranova-club.css'] as $legacyImport) {
    $notContains($workspaceEntry, $legacyImport, 'Workspace entrypoint must not directly depend on legacy/global stylesheet.');
}

$vite = $read('vite.config.js');
foreach (["'public-surface'", "'terranova-interface'"] as $requiredEntry) {
    $contains($vite, $requiredEntry, 'Vite is missing canonical surface entrypoint.');
}
$notContains($layout, "'portal' => 'portal-cabinet'", 'PHTML root layout must not own the Wave 13 Portal runtime.');
$notContains($vite, "'portal-cabinet'", 'Portal must stay retired from the Vite runtime.');

foreach (["'terranova-club'", "'terranova-home'"] as $retiredEntry) {
    $notContains($vite, $retiredEntry, 'Retired legacy entrypoint returned to Vite runtime.');
}

preg_match_all("/resolve\\(import\\.meta\\.dirname, '([^']+\\.js)'\\)/", $vite, $runtimeEntryMatches);
if (empty($runtimeEntryMatches[1])) {
    throw new RuntimeException('Unable to resolve canonical Vite runtime entrypoints for legacy audit.');
}
foreach (array_unique($runtimeEntryMatches[1]) as $entrypointPath) {
    $source = $read($entrypointPath);
    if (preg_match("/(?:import|@import)[^;\\n]*terranova-club(?:\\.css|\\.js)?/", $source)) {
        throw new RuntimeException('Canonical runtime entrypoint restored terranova-club dependency: ' . $entrypointPath);
    }
    if (preg_match("/(?:import|@import)[^;\\n]*terranova-home(?:\\.css|\\.js)?/", $source)) {
        throw new RuntimeException('Canonical runtime entrypoint restored terranova-home dependency: ' . $entrypointPath);
    }
}

$publicInteractions = $read('frontend/features/public/interactions.js');
$notContains($publicInteractions, "preventDefault()", 'Public extraction must not fake successful backend form submissions.');
$notContains($publicInteractions, "Заявку підготовлено до передачі", 'Historical fake CRM confirmation must not return.');
foreach (['localStorage', 'sessionStorage'] as $forbiddenPersistence) {
    $notContains($publicInteractions, $forbiddenPersistence, 'Public interactions must not restore browser persistence.');
}
foreach ([
    '/api/v1/public/properties/favourites',
    'campaignFromLocation',
    'window.location.search',
] as $marker) {
    $contains($publicInteractions, $marker, 'Public interactions persistence/attribution contract is incomplete.');
}

$assetGate = $read('tests/architecture/frontend_assets.php');
foreach (["'public-surface'", "'terranova-interface'"] as $needle) {
    $contains($assetGate, $needle, 'Frontend asset gate must cover canonical Vite entrypoints.');
}
$notContains($assetGate, "'cos-architecture-explorer'", 'Architecture Explorer is now owned by Symfony AssetMapper/Stimulus, not Vite.');
$entriesStart = strpos($assetGate, '$entries = [');
$entriesEnd = $entriesStart === false ? false : strpos($assetGate, '];', $entriesStart);
if ($entriesStart === false || $entriesEnd === false) {
    throw new RuntimeException('Frontend asset gate entry list cannot be inspected.');
}
$assetEntryList = substr($assetGate, $entriesStart, $entriesEnd - $entriesStart);
foreach (["'terranova-club'", "'terranova-home'"] as $retiredEntry) {
    $notContains($assetEntryList, $retiredEntry, 'Frontend asset gate must not require retired entrypoint.');
}

$legacyAudit = $read('docs/architecture/frontend-legacy-audit.md');
foreach ([
    'USED',
    'MIGRATED',
    'DEAD',
    '## Browser persistence closure',
    '/api/v1/public/properties/favourites',
    'current URL',
    'localStorage',
    'sessionStorage',
    'tests/architecture/web_v012_production_closure.php',
] as $needle) {
    $contains($legacyAudit, $needle, 'Legacy audit is incomplete.');
}
$notContains($legacyAudit, '## Browser persistence debt', 'Legacy audit must not restore already-closed browser persistence debt.');
$notContains($legacyAudit, 'still uses:', 'Legacy audit must not claim canonical interactions still use browser storage.');

$productionClosure = $read('tests/architecture/web_v012_production_closure.php');
foreach ([
    "RecursiveDirectoryIterator(\$root.'/frontend'",
    "'localStorage'",
    "'sessionStorage'",
    'Browser persistence cannot be canonical state',
] as $needle) {
    $contains($productionClosure, $needle, 'WEB V0.12 browser persistence gate is incomplete.');
}

echo "WEB V0.11 design system and legacy extraction architecture passed.\n";
