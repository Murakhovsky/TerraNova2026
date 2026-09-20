<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_api_v1_public_properties:',
    'path: /api/v1/public/properties',
    'cos_api_v1_public_properties_featured:',
    'path: /api/v1/public/properties/featured',
    'cos_api_v1_public_property:',
    'App\\Http\\Api\\V1\\Controller\\PublicPropertyController',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Public Property Symfony route contract missing: ' . $needle);
}

$controller = $read('symfony/src/Http/Api/V1/Controller/PublicPropertyController.php');
foreach ([
    'CatalogService',
    'catalogProperties',
    'featuredProperties',
    'propertyBySlug',
    "['published', 'active']",
] as $needle) {
    $assert(str_contains($controller, $needle), 'Public Property controller contract missing: ' . $needle);
}
$assert(!str_contains($controller, 'TenantContextProviderInterface'), 'Public Property reads must not require tenant authentication.');

$services = $read('symfony/config/services.yaml');
foreach ([
    'Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection:',
    "      \$config: '@legacy_cos.pdo'",
    'Domains\\Property\\Infrastructure\\ReadModel\\MySql\\CatalogService:',
] as $needle) {
    $assert(str_contains($services, $needle), 'Public Property read-model wiring missing: ' . $needle);
}

$security = $read('symfony/config/packages/security.yaml');
$assert(
    str_contains($security, "^/api/v1/public/properties(?:/|$)"),
    'Public Property API is not explicitly PUBLIC_ACCESS.',
);
$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
$assert(
    str_contains($authenticator, 'api/v1/public/properties'),
    'Legacy session authenticator still captures public Property reads.',
);

$legacyRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach ([
    '/api/v1/properties',
    '/api/property/:action',
] as $retired) {
    $assert(!str_contains($legacyRoutes, $retired), 'Retired Phalcon Property read route restored: ' . $retired);
}
$assert(str_contains($legacyRoutes, '/api/property/favourites'), 'Session favourites compatibility route is missing.');

$legacyApi = $read('app/Interfaces/Web/Controller/ApiController.php');
foreach (['catalogAction', 'featuredAction', 'showAction', 'propertyCardPayload', 'propertyDetailPayload'] as $retired) {
    $assert(!str_contains($legacyApi, $retired), 'Retired Phalcon Property read action restored: ' . $retired);
}
$assert(str_contains($legacyApi, 'favouritesAction'), 'Favourites compatibility action must remain until session cutover.');

$catalogJs = $read('frontend/entrypoints/terranova-catalog-api.js');
$catalogView = $read('app/Interfaces/Web/View/property/catalog.phtml');
$homeView = $read('app/Interfaces/Web/View/index/index.phtml');
foreach ([$catalogJs, $catalogView] as $source) {
    $assert(str_contains($source, '/api/v1/public/properties'), 'Public catalog browser source is not using Symfony public reads.');
    $assert(!str_contains($source, "'/api/v1/properties'"), 'Public catalog browser source still targets manager Property API.');
}
$assert(str_contains($homeView, 'api/v1/public/properties/featured'), 'Homepage featured feed is not using Symfony public reads.');

echo "Public Property Symfony read cutover boundary OK\n";
