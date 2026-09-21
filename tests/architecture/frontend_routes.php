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
    'cos_web_property_root:',
    'cos_web_property_catalog:',
    'cos_web_property_map:',
    'cos_web_property_show:',
    'cos_web_property_presentation:',
    'cos_web_property_pdf:',
    'cos_web_property_type:',
    'cos_web_property_city:',
    'cos_web_property_submit:',
    'cos_web_property_manage:',
    'cos_web_property_listing:',
    'cos_web_property_submissions:',
    'cos_web_property_submission:',
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

foreach ([
    '/property/catalog',
    '/property/map',
    '/property/show/{slug}',
    '/property/presentation/{slug}',
    '/property/submit',
    '/property/manage',
    '/property/listing',
    '/property/submissions',
    '/property/submission/{id}',
] as $requiredPagePath) {
    if (!isset($routePaths[$requiredPagePath])) {
        throw new RuntimeException('Recovered Property page route is missing: ' . $requiredPagePath);
    }
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
$sessionAuthenticator = (string) file_get_contents($root . '/symfony/src/Security/SessionAuthenticator.php');

foreach ([
    'client-case',
    'cos/(?:architecture|control-center|action|approval)',
    'admin',
    'property/(?:manage|listing|submissions|submission|presentationShare)',
] as $protectedPattern) {
    if (!str_contains($security, $protectedPattern)) {
        throw new RuntimeException('Recovered Workspace route family is missing from Symfony firewall: ' . $protectedPattern);
    }
}

foreach ([
    "str_starts_with(\$path,'/client-case')",
    "str_starts_with(\$path,'/cos/control-center')",
    "str_starts_with(\$path,'/cos/action')",
    "str_starts_with(\$path,'/cos/approval')",
    "str_starts_with(\$path,'/admin')",
    "property/(?:manage|listing|submissions|submission|presentationShare)",
] as $authBoundary) {
    if (!str_contains($sessionAuthenticator, $authBoundary)) {
        throw new RuntimeException('Recovered Workspace route family is missing from native session authentication: ' . $authBoundary);
    }
}

$framework = (string) file_get_contents($root . '/symfony/config/packages/framework.yaml');
foreach ([
    'name: COSSESSID',
    'cookie_secure: auto',
    'cookie_httponly: true',
    'cookie_samesite: lax',
] as $sessionMarker) {
    if (!str_contains($framework, $sessionMarker)) {
        throw new RuntimeException('Native Symfony session cookie policy is missing: ' . $sessionMarker);
    }
}

echo "Frontend route declaration and navigation integrity contract passed on Symfony-only routing.\n";
