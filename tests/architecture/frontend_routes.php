<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$frontendRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');

$required = [
    '/api/v1/properties',
    '/api/v1/properties/featured',
    '/api/v1/properties/{slug:[a-z0-9-]+}',
    '/property',
    '/client-case',
    '/admin/content',
    '/admin/content/edit',
    '/cos/control-center',
];
foreach ($required as $pattern) {
    if (!str_contains($frontendRoutes, "'" . $pattern . "'")) {
        throw new RuntimeException('Missing frontend route declaration: ' . $pattern);
    }
}

foreach (['/sales/dashboard', '/sales/pipeline', '/sales/today', '/sales/leads', '/sales/deals', '/sales/director', '/sales/admin'] as $migrated) {
    if (str_contains($frontendRoutes, "'" . $migrated . "'")) {
        throw new RuntimeException('Symfony-owned Sales page route leaked back into Phalcon FrontendRoutes: ' . $migrated);
    }
}

$symfonyRoutes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'cos_web_sales_root:', 'cos_web_sales_dashboard:', 'cos_web_sales_today:', 'cos_web_sales_pipeline:',
    'cos_web_sales_leads:', 'cos_web_sales_deals:', 'cos_web_sales_deal:', 'cos_web_sales_director:', 'cos_web_sales_admin:',
] as $route) {
    if (!str_contains($symfonyRoutes, $route)) {
        throw new RuntimeException('Canonical Symfony Sales page route is missing: ' . $route);
    }
}

foreach (['/api/sales/', '/api/integrations/{organization:', "'crm_webhook'", '/api/health', '/api/admin/diagnostics', '/api/cos/actions', '/api/cos/approvals', '/api/cos/rules', '/api/cos/audit'] as $retired) {
    if (str_contains($frontendRoutes, $retired)) {
        throw new RuntimeException('Retired legacy API route restored in FrontendRoutes: ' . $retired);
    }
}

// Public CMS pages are generated from an allow-list rather than hard-coded route literals.
foreach (['$publicPageSlugs', "\$router->add('/' . \$slug", "'page', 'show'", "['slug' => \$slug]"] as $needle) {
    if (!str_contains($frontendRoutes, $needle)) {
        throw new RuntimeException('Public page route generation is missing: ' . $needle);
    }
}

// Quarantined legacy modules are registered through one shared loop.
foreach (["foreach (['economy', 'games', 'users'] as \$deprecatedModule)", "'/' . \$deprecatedModule . '/{path:.*}'", "'deprecated_module', 'gone'"] as $needle) {
    if (!str_contains($frontendRoutes, $needle)) {
        throw new RuntimeException('Deprecated module route contract is missing: ' . $needle);
    }
}

// Phalcon evaluates newer routes before older generic matches in this registration model.
// Keep the concrete featured endpoint registered after the dynamic property slug route.
$slugPosition = strpos($frontendRoutes, "'/api/v1/properties/{slug:[a-z0-9-]+}'");
$featuredPosition = strpos($frontendRoutes, "'/api/v1/properties/featured'");
if ($slugPosition === false || $featuredPosition === false || $featuredPosition < $slugPosition) {
    throw new RuntimeException('Static featured endpoint registration must remain after the property slug route.');
}

// Detect duplicate completed literal method+path declarations without loading Phalcon.
// Dynamic expressions such as '/' . $slug are intentionally excluded.
$identities = [];
$selfAddPattern = <<<'REGEX'
~self::add\(\$router,\s*'([^']+)',\s*'([^']+)'\s*,~
REGEX;
$directAddPattern = <<<'REGEX'
~\$router->(add(?:Get|Post|Put|Delete|Patch)?)\(\s*'([^']+)'\s*,~
REGEX;
if (preg_match_all($selfAddPattern, $frontendRoutes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $identities[] = strtolower($match[1]) . ' ' . $match[2];
    }
}
if (preg_match_all($directAddPattern, $frontendRoutes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $identities[] = strtolower($match[1]) . ' ' . $match[2];
    }
}
if (count($identities) !== count(array_unique($identities))) {
    $duplicates = array_keys(array_filter(array_count_values($identities), static fn(int $count): bool => $count > 1));
    throw new RuntimeException('Frontend route registration contains duplicate literal identities: ' . implode(', ', $duplicates));
}

$webBootstrap = (string) file_get_contents($root . '/app/bootstrap_web.php');
$normalizedBootstrap = str_replace('\\\\', '\\', $webBootstrap);
foreach (['Modules\\Economy\\Module', 'Modules\\Games\\Module', 'Modules\\Users\\Module'] as $quarantinedModule) {
    if (str_contains($normalizedBootstrap, $quarantinedModule)) {
        throw new RuntimeException('Quarantined module is registered in the main web application: ' . $quarantinedModule);
    }
}
if (!str_contains($normalizedBootstrap, 'Interfaces\\Web\\Module')) {
    throw new RuntimeException('Canonical frontend web module is not registered.');
}
if (str_contains($normalizedBootstrap, 'Bootstrap\\SpatialModule')) {
    throw new RuntimeException('Retired Phalcon Spatial module is still registered.');
}

if (str_contains($frontendRoutes, 'Modules\\Frontend\\Controllers')) {
    throw new RuntimeException('Frontend routes must target Interfaces\\Web\\Controller.');
}

$legacyModulesDir = $root . '/app/modules';
if (is_dir($legacyModulesDir)) {
    throw new RuntimeException('Legacy app/modules directory must not be restored.');
}

$routeBootstrap = (string) file_get_contents($root . '/app/config/routes.php');
if (str_contains($routeBootstrap, '/:controller/:action/:params')) {
    throw new RuntimeException('Generic module-prefixed routes must not be generated.');
}

echo "Frontend route declaration contract passed.\n";
