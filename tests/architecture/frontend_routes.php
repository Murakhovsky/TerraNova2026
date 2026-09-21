<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routesPath = $root . '/symfony/config/routes.yaml';
if (!is_file($routesPath)) {
    throw new RuntimeException('Canonical Symfony route configuration is missing.');
}
$routes = (string) file_get_contents($routesPath);

foreach ([
    'cos_symfony_home:',
    'cos_web_cabinet:',
    'cos_web_cabinet_submission_retired:',
    'cos_web_workspace_home:',
    'cos_web_workspace_users:',
    'cos_web_workspace_analytics:',
    'cos_web_client_cases:',
    'cos_web_client_case_inbox:',
    'cos_web_property_catalog:',
    'cos_web_property_map:',
    'cos_web_property_manage:',
    'cos_web_property_listing:',
    'cos_web_property_submissions:',
    'cos_web_cos_control_center:',
    'cos_web_sales_root:',
    'cos_web_sales_dashboard:',
    'cos_web_sales_today:',
    'cos_web_sales_pipeline:',
    'cos_web_sales_leads:',
    'cos_web_sales_deals:',
    'cos_web_sales_deal:',
    'cos_web_sales_director:',
    'cos_web_sales_admin:',
    'cos_api_v1_public_properties:',
    'cos_api_v1_public_properties_featured:',
    'cos_api_v1_public_property:',
    'cos_web_robots:',
    'cos_web_sitemap:',
    'cos_web_blog_index:',
    'cos_web_blog_article:',
    'cos_web_guide:',
    'cos_web_public_analytics:',
    'cos_web_n8n_content_webhook:',
] as $route) {
    if (!str_contains($routes, $route)) {
        throw new RuntimeException('Canonical Symfony route is missing: ' . $route);
    }
}

foreach ([
    'app/Interfaces/Web/Routing/FrontendRoutes.php',
    'app/config/routes.php',
    'app/bootstrap_web.php',
    'app/Interfaces/Web/Module.php',
] as $retired) {
    if (file_exists($root . '/' . $retired)) {
        throw new RuntimeException('Retired Phalcon route composition returned: ' . $retired);
    }
}

foreach ([
    '/api/property/favourites',
    '/api/property/:action',
    '/api/sales/',
    '/api/health',
] as $legacyPath) {
    if (str_contains($routes, $legacyPath)) {
        throw new RuntimeException('Legacy route leaked into Symfony route configuration: ' . $legacyPath);
    }
}

$routePaths = [];
foreach (preg_split('/\n(?=[A-Za-z0-9_]+:\n)/', $routes) ?: [] as $block) {
    if (!preg_match('/^\s*path:\s*(\/[^\s#]+)/m', $block, $pathMatch)) {
        continue;
    }
    if (!preg_match('/^\s*methods:\s*\[([^\]]+)\]/m', $block, $methodMatch)) {
        continue;
    }
    $methods = array_map('trim', explode(',', $methodMatch[1]));
    if (in_array('GET', $methods, true) || in_array('HEAD', $methods, true)) {
        $routePaths[$pathMatch[1]] = true;
    }
}

$navigationSources = [
    'symfony/src/Web/Navigation/NavigationBuilder.php' => "/['\"]path['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",
    'symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php' => "/new\s+NavigationContribution\([^,]+,\s*[^,]+,\s*['\"]([^'\"]+)['\"]/",
    'symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php' => "/new\s+NavigationContribution\([^,]+,\s*[^,]+,\s*['\"]([^'\"]+)['\"]/",
    'symfony/src/Web/Experience/Extension/Provider/PropertyWebProvider.php' => "/new\s+NavigationContribution\([^,]+,\s*[^,]+,\s*['\"]([^'\"]+)['\"]/",
];

$missing = [];
foreach ($navigationSources as $relative => $pattern) {
    $full = $root . '/' . $relative;
    if (!is_file($full)) {
        continue;
    }
    $source = (string) file_get_contents($full);
    preg_match_all($pattern, $source, $matches);
    foreach ($matches[1] ?? [] as $navigationPath) {
        $path = '/' . ltrim((string) parse_url((string) $navigationPath, PHP_URL_PATH), '/');
        if ($path === '/') {
            continue;
        }
        if (!isset($routePaths[$path])) {
            $missing[$path] = $relative;
        }
    }
}

if ($missing !== []) {
    $lines = [];
    foreach ($missing as $path => $source) {
        $lines[] = $path . ' <- ' . $source;
    }
    throw new RuntimeException("Navigation points to missing Symfony GET/HEAD routes:\n - " . implode("\n - ", $lines));
}

$legacyModulesDir = $root . '/app/modules';
if (is_dir($legacyModulesDir)) {
    throw new RuntimeException('Legacy app/modules directory must not be restored.');
}

echo "Frontend route declaration and navigation integrity contract passed on Symfony-only routing.\n";
