<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$frontendRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');

$required = [
    '/api/health',
    '/api/v1/properties',
    '/api/v1/properties/featured',
    '/api/v1/properties/{slug:[a-z0-9-]+}',
    '/api/sales/dashboard',
    '/api/sales/leads',
    '/api/sales/deals',
    '/api/sales/deals/{id:[0-9]+}/timeline',
    '/api/sales/deals/{id:[0-9]+}/intelligence',
    '/api/sales/deals/{id:[0-9]+}/stage',
    '/api/sales/actions/{id:[a-f0-9]{32}}/outcomes',
    '/api/cos/actions',
    '/api/cos/approvals',
    '/api/cos/rules',
    '/api/cos/audit',
    '/api/integrations/{organization:[a-zA-Z0-9_-]+}/crm/{provider:[a-zA-Z0-9_-]+}/webhook',
    '/property',
    '/client-case',
    '/admin/content',
    '/admin/content/edit',
    '/cos/control-center',
    '/sales/dashboard',
    '/sales/pipeline',
    '/sales/today',
];
foreach ($required as $pattern) {
    if (!str_contains($frontendRoutes, "'" . $pattern . "'")) {
        throw new RuntimeException('Missing frontend route declaration: ' . $pattern);
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

// Detect duplicate literal method+path declarations without loading the Phalcon extension.
$identities = [];
if (preg_match_all("/self::add\\(\\$router,\\s*'([^']+)',\\s*'([^']+)'/", $frontendRoutes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $identities[] = strtolower($match[1]) . ' ' . $match[2];
    }
}
if (preg_match_all("/\\$router->(add(?:Get|Post|Put|Delete|Patch)?)\\(\\s*'([^']+)'/", $frontendRoutes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $identities[] = strtolower($match[1]) . ' ' . $match[2];
    }
}
if (count($identities) !== count(array_unique($identities))) {
    $duplicates = array_keys(array_filter(array_count_values($identities), static fn(int $count): bool => $count > 1));
    throw new RuntimeException('Frontend route registration contains duplicate literal identities: ' . implode(', ', $duplicates));
}

$webBootstrap = (string) file_get_contents($root . '/app/bootstrap_web.php');
foreach (['Modules\\Economy\\Module', 'Modules\\Games\\Module', 'Modules\\Users\\Module'] as $quarantinedModule) {
    if (str_contains($webBootstrap, $quarantinedModule)) {
        throw new RuntimeException('Quarantined module is registered in the main web application: ' . $quarantinedModule);
    }
}
foreach (['Interfaces\\Web\\Module', 'Bootstrap\\SpatialModule'] as $canonicalModule) {
    if (!str_contains($webBootstrap, $canonicalModule)) {
        throw new RuntimeException('Canonical web module is not registered: ' . $canonicalModule);
    }
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
