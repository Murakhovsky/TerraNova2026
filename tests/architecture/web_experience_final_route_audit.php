<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routesPath = $root . '/symfony/config/routes.yaml';
$routesSource = (string) file_get_contents($routesPath);

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

$routeBlocks = [];
if (preg_match_all('/^([A-Za-z0-9_.-]+):\R((?:^[ \t].*\R?)*)/m', $routesSource, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $block = (string) $match[2];
        if (!preg_match('/^  path:\s*(.+)$/m', $block, $pathMatch)) {
            continue;
        }
        if (!preg_match('/^  controller:\s*(.+)$/m', $block, $controllerMatch)) {
            $fail('Route has path but no controller: ' . $match[1]);
        }
        preg_match('/^  methods:\s*\[(.*)]$/m', $block, $methodMatch);
        $routeBlocks[] = [
            'name' => (string) $match[1],
            'path' => trim((string) $pathMatch[1], " \t\n\r\0\x0B'\""),
            'controller' => trim((string) $controllerMatch[1], " \t\n\r\0\x0B'\""),
            'methods' => isset($methodMatch[1])
                ? array_values(array_filter(array_map(static fn(string $value): string => trim($value), explode(',', $methodMatch[1]))))
                : [],
        ];
    }
}

if (count($routeBlocks) < 200) {
    $fail('Route audit parsed suspiciously few routes: ' . count($routeBlocks));
}

$controllerFiles = [];
foreach ($routeBlocks as $route) {
    $controller = $route['controller'];
    [$class, $method] = array_pad(explode('::', $controller, 2), 2, null);

    if (!str_starts_with($class, 'App\\')) {
        $fail('Route controller is outside canonical App namespace: ' . $route['name'] . ' -> ' . $controller);
    }

    $relative = 'symfony/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    $full = $root . '/' . $relative;
    if (!is_file($full)) {
        $fail('Route controller file is missing: ' . $route['name'] . ' -> ' . $relative);
    }

    $source = $controllerFiles[$relative] ??= (string) file_get_contents($full);
    $expected = $method !== null && $method !== '' ? $method : '__invoke';
    if (preg_match('/function\s+' . preg_quote($expected, '/') . '\s*\(/', $source) !== 1) {
        $fail('Route controller method is missing: ' . $route['name'] . ' -> ' . $controller);
    }
}

$catalogSource = (string) file_get_contents($root . '/app/Domains/Content/Application/Service/PublicPageCatalog.php');
if (!preg_match_all("/'path'\s*=>\s*'([a-z0-9-]+)'/", $catalogSource, $pageMatches)) {
    $fail('PublicPageCatalog exposes no static pages.');
}
$publicPageRoute = null;
foreach ($routeBlocks as $route) {
    if ($route['name'] === 'cos_web_public_page') {
        $publicPageRoute = $route;
        break;
    }
}
if ($publicPageRoute === null || $publicPageRoute['path'] !== '/{slug}') {
    $fail('PublicPageCatalog pages have no canonical /{slug} route.');
}
$routeBlockOffset = strpos($routesSource, "cos_web_public_page:");
$routeBlockSlice = $routeBlockOffset === false ? '' : substr($routesSource, $routeBlockOffset, 500);
foreach (array_values(array_unique($pageMatches[1])) as $slug) {
    if (!str_contains($routeBlockSlice, $slug)) {
        $fail('PublicPageCatalog slug is not routable: /' . $slug);
    }
}

$viewRoot = $root . '/app/Interfaces/Web/View';
$views = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
    $key = substr($relative, 0, -strlen('.phtml'));
    $views[$key] = $file->getPathname();
}
if (count($views) < 50) {
    $fail('View graph contains suspiciously few PHTML views: ' . count($views));
}

$roots = ['index' => true];
$sourceRoots = [$root . '/symfony/src', $root . '/app'];
foreach ($sourceRoots as $sourceRoot) {
    $sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($sourceIterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $normalized = str_replace('\\', '/', $file->getPathname());
        if (str_contains($normalized, '/app/Interfaces/Web/View/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach ($views as $key => $_path) {
            if (preg_match('/[\'\"]' . preg_quote($key, '/') . '(?:\.phtml)?[\'\"]/', $source) === 1) {
                $roots[$key] = true;
            }
        }
    }
}

$reachable = [];
$queue = array_keys($roots);
while ($queue !== []) {
    $key = array_shift($queue);
    if (isset($reachable[$key]) || !isset($views[$key])) {
        continue;
    }
    $reachable[$key] = true;
    $source = (string) file_get_contents($views[$key]);

    foreach ($views as $candidate => $_path) {
        if (isset($reachable[$candidate])) {
            continue;
        }
        if (preg_match('/[\'\"]' . preg_quote($candidate, '/') . '[\'\"]/', $source) === 1) {
            $queue[] = $candidate;
        }
    }
}

$orphans = array_values(array_diff(array_keys($views), array_keys($reachable)));
sort($orphans);
if ($orphans !== []) {
    $fail("Unreachable PHTML views detected:\n- " . implode("\n- ", $orphans));
}

$retiredViews = [
    'index/index',
    'company_os/index',
    'company_os/domain',
    'company_os/not_found',
];
foreach ($retiredViews as $retired) {
    if (isset($views[$retired])) {
        $fail('Retired PHTML view restored: ' . $retired);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
$assetGate = (string) file_get_contents($root . '/tests/architecture/frontend_assets.php');
$layout = (string) file_get_contents($root . '/app/Interfaces/Web/View/index.phtml');
foreach (['cos-site', 'frontend/entrypoints/cos-site.js', 'frontend/styles/cos-site.css'] as $retiredAsset) {
    if (str_contains($vite, $retiredAsset) || str_contains($assetGate, $retiredAsset) || str_contains($layout, $retiredAsset)) {
        $fail('Retired COS-site asset contract restored: ' . $retiredAsset);
    }
}
if (is_file($root . '/frontend/entrypoints/cos-site.js') || is_file($root . '/frontend/styles/cos-site.css')) {
    $fail('Retired COS-site source files were restored.');
}

foreach (['tn-filter-bar', 'tn-crm-filters', 'tn-manage-filters', 'tn-ui-toolbar', 'tn-status-pill'] as $legacyMarker) {
    foreach ($views as $key => $path) {
        if (str_contains((string) file_get_contents($path), $legacyMarker)) {
            $fail('Legacy UI primitive restored in ' . $key . ': ' . $legacyMarker);
        }
    }
}

$staticHtmlRoutes = array_values(array_filter($routeBlocks, static function (array $route): bool {
    if (str_contains($route['path'], '{')) {
        return false;
    }
    $methods = $route['methods'];
    if ($methods !== [] && !in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
        return false;
    }
    if (!str_starts_with($route['name'], 'cos_web_') && $route['name'] !== 'cos_symfony_home') {
        return false;
    }
    return preg_match('/(?:api|health|sitemap|robots|telemetry|webhook|search|retry|publish|data_export|graph)/i', $route['name']) !== 1;
}));

echo json_encode([
    'ok' => true,
    'suite' => 'Final UI route/view audit',
    'routes' => count($routeBlocks),
    'static_html_routes' => count($staticHtmlRoutes),
    'views' => count($views),
    'reachable_views' => count($reachable),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
