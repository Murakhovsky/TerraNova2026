<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Interfaces\Web\Routing\CoreWebRoutes;
use Interfaces\Web\Routing\FrontendRoutes;
use Interfaces\Web\Routing\SpatialWebRoutes;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Router;

$services = (string) file_get_contents($root . '/app/config/services_web.php');
foreach (['new Router(false)', 'removeExtraSlashes(true)'] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('WEB V0.13 router configuration is missing: ' . $needle);
    }
}
if (preg_match('/new\s+Router\s*\(\s*\)/', $services) === 1) {
    throw new RuntimeException('Phalcon default routes must stay disabled.');
}

$router = new Router(false);
$router->setDI(new FactoryDefault());
$router->removeExtraSlashes(true);
FrontendRoutes::register($router, ['about']);
SpatialWebRoutes::register($router);
CoreWebRoutes::register($router);

$patterns = [];
$routeMethods = [];
foreach ($router->getRoutes() as $route) {
    $pattern = (string) $route->getPattern();
    $patterns[] = $pattern;
    $methods = $route->getHttpMethods();
    $routeMethods[$pattern] = is_array($methods) ? $methods : ($methods ? [(string) $methods] : []);
}

$required = [
    '/',
    '/auth/login',
    '/auth/register',
    '/auth/logout',
    '/cabinet',
    '/cabinet/submission/{id:[0-9]+}',
    '/cabinet/telegramConnect',
    '/cabinet/telegramDisconnect',
    '/admin',
    '/admin/users',
    '/admin/analytics',
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
    '/spatial/manage',
    '/spatial/edit',
    '/spatial/edit/{id:[0-9]+}',
    '/spatial/save/{id:[0-9]+}',
    '/spatial/upload/{id:[0-9]+}',
    '/spatial/external/{id:[0-9]+}',
    '/spatial/capture/{id:[0-9]+}',
    '/spatial/hotspot/{id:[0-9]+}',
    '/spatial/publish/{id:[0-9]+}',
    '/spatial/scene/{slug:[A-Za-z0-9_-]+}',
];
foreach ($required as $pattern) {
    if (!in_array($pattern, $patterns, true)) {
        throw new RuntimeException('WEB V0.13 explicit route is missing: ' . $pattern);
    }
}

foreach (['/:controller', '/:controller/:action', '/:controller/:action/:params'] as $defaultPattern) {
    if (in_array($defaultPattern, $patterns, true)) {
        throw new RuntimeException('Global Phalcon default route leaked back into the explicit router: ' . $defaultPattern);
    }
}

foreach ([
    '/cabinet/telegramConnect',
    '/cabinet/telegramDisconnect',
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
    '/spatial/save/{id:[0-9]+}',
    '/spatial/upload/{id:[0-9]+}',
    '/spatial/external/{id:[0-9]+}',
    '/spatial/capture/{id:[0-9]+}',
    '/spatial/hotspot/{id:[0-9]+}',
    '/spatial/publish/{id:[0-9]+}',
] as $mutationPattern) {
    $methods = array_map('strtoupper', $routeMethods[$mutationPattern] ?? []);
    if (!in_array('POST', $methods, true)) {
        throw new RuntimeException('Mutation route must be POST-only: ' . $mutationPattern);
    }
}

$assertRoute = static function (Router $router, string $uri, string $controller, string $action): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $router->handle($uri);
    if ($router->getControllerName() !== $controller || $router->getActionName() !== $action) {
        throw new RuntimeException(sprintf(
            'Route %s resolved to %s/%s instead of %s/%s.',
            $uri,
            (string) $router->getControllerName(),
            (string) $router->getActionName(),
            $controller,
            $action,
        ));
    }
};

foreach ([
    ['/', 'index', 'index'],
    ['/auth/login', 'auth', 'login'],
    ['/cabinet', 'cabinet', 'index'],
    ['/cabinet/submission/42', 'cabinet', 'submission'],
    ['/admin', 'admin', 'index'],
    ['/admin/users', 'admin', 'users'],
    ['/admin/analytics', 'admin', 'analytics'],
    ['/spatial/manage', 'spatial', 'manage'],
    ['/spatial/edit/42', 'spatial', 'edit'],
    ['/spatial/scene/demo-scene', 'spatial', 'scene'],
] as [$uri, $controller, $action]) {
    $assertRoute($router, $uri, $controller, $action);
}

foreach (['/cabinet/index', '/admin/index', '/this-route-does-not-exist'] as $unknownUri) {
    $assertRoute($router, $unknownUri, 'error', 'notFound');
}

$module = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
foreach (['SpatialWebRoutes::register($router)', 'CoreWebRoutes::register($router)'] as $needle) {
    if (!str_contains($module, $needle)) {
        throw new RuntimeException('Web module is missing explicit route registration: ' . $needle);
    }
}
$moduleRoutesPosition = strpos($module, '$routeRegistrar->register');
$coreRoutesPosition = strpos($module, 'CoreWebRoutes::register($router)');
if ($moduleRoutesPosition === false || $coreRoutesPosition === false || $coreRoutesPosition < $moduleRoutesPosition) {
    throw new RuntimeException('Core not-found registration must happen after module route contributors.');
}

$errorController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/ErrorController.php');
foreach (["renderFrontendFailure(404", "'not_found'", "'/api/'", "return 'portal'", "return 'workspace'", "return 'public'"] as $needle) {
    if (!str_contains($errorController, $needle)) {
        throw new RuntimeException('Canonical 404 controller is missing behavior: ' . $needle);
    }
}

$documentation = (string) file_get_contents($root . '/docs/architecture/web-v0.13.md');
foreach (['Router(false)', 'CoreWebRoutes', 'SpatialWebRoutes', 'application-wide 404', 'module route contributors'] as $needle) {
    if (!str_contains($documentation, $needle)) {
        throw new RuntimeException('WEB V0.13 documentation is missing contract: ' . $needle);
    }
}

echo "WEB V0.13 explicit routing and 404 closure passed.\n";
