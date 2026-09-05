<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/app/Interfaces/Web/Routing/FrontendRoutes.php';

$router = new Phalcon\Mvc\Router(false);
$router->setDI(new Phalcon\Di\FactoryDefault());
Interfaces\Web\Routing\FrontendRoutes::register($router, ['about']);

$patterns = [];
foreach ($router->getRoutes() as $route) {
    $patterns[] = $route->getPattern();
}

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
    '/about',
    '/economy/{path:.*}',
    '/games/{path:.*}',
    '/users/{path:.*}',
];
foreach ($required as $pattern) {
    if (!in_array($pattern, $patterns, true)) {
        throw new RuntimeException('Missing frontend route: ' . $pattern);
    }
}

$router->handle('/api/v1/properties/featured');
if ($router->getControllerName() !== 'api' || $router->getActionName() !== 'featured') {
    throw new RuntimeException('Static featured endpoint is shadowed by the property slug route.');
}

if (count($patterns) !== count(array_unique($patterns))) {
    throw new RuntimeException('Frontend route registration contains duplicate patterns.');
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

$frontendRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
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

echo "Frontend route registration passed.\n";
