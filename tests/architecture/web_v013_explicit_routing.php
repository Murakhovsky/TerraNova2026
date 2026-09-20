<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$services = $read('app/config/services_web.php');
foreach (['new Router(false)', 'removeExtraSlashes(true)'] as $needle) {
    $assert(str_contains($services, $needle), 'WEB V0.13 router configuration is missing: ' . $needle);
}
$assert(preg_match('/new\s+Router\s*\(\s*\)/', $services) !== 1, 'Phalcon default routes must stay disabled.');

$coreRoutes = $read('app/Interfaces/Web/Routing/CoreWebRoutes.php');
$coreRequired = [
    '/',
    '/auth/login',
    '/auth/register',
    '/auth/logout',
    '/cabinet',
    '/cabinet/submission/{id:[0-9]+}',
    '/admin',
    '/admin/users',
    '/admin/analytics',
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
];
foreach ($coreRequired as $pattern) {
    $assert(str_contains($coreRoutes, "'" . $pattern . "'"), 'WEB V0.13 core explicit route is missing: ' . $pattern);
}

foreach ([
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
] as $mutationPattern) {
    $assert(
        str_contains($coreRoutes, "addPost('" . $mutationPattern . "'"),
        'Core mutation route must stay POST-only: ' . $mutationPattern,
    );
}

foreach (['/:controller', '/:controller/:action', '/:controller/:action/:params'] as $defaultPattern) {
    $assert(!str_contains($coreRoutes, $defaultPattern), 'Global Phalcon default-style route leaked into V0.13 declarations: ' . $defaultPattern);
}

foreach ([
    "\$router->add('/', \$web('index', 'index'))",
    "\$router->add('/auth/login', \$web('auth', 'login'))",
    "\$router->add('/cabinet', \$web('cabinet', 'index'))",
    "\$router->add('/cabinet/submission/{id:[0-9]+}', \$web('cabinet', 'submission')",
    "\$router->add('/admin', \$web('admin', 'index'))",
    "\$router->add('/admin/users', \$web('admin', 'users'))",
    "\$router->add('/admin/analytics', \$web('admin', 'analytics'))",
] as $mapping) {
    $assert(str_contains($coreRoutes, $mapping), 'Core route target mapping is missing: ' . $mapping);
}

foreach (['/cabinet/index', '/admin/index'] as $forbiddenAlias) {
    $assert(!str_contains($coreRoutes, "'" . $forbiddenAlias . "'"), 'Implicit-style alias must not become explicit again: ' . $forbiddenAlias);
}
$assert(str_contains($coreRoutes, "\$router->notFound(\$web('error', 'notFound'))"), 'Canonical legacy application-wide not-found target is missing.');

$module = $read('app/Interfaces/Web/Module.php');
$assert(str_contains($module, 'CoreWebRoutes::register($router)'), 'Web module is missing core explicit route registration.');
$assert(!str_contains($module, 'SpatialWebRoutes::register($router)'), 'Retired Phalcon Spatial route ownership was restored.');
$assert(!is_file($root . '/app/Interfaces/Web/Routing/SpatialWebRoutes.php'), 'Retired SpatialWebRoutes.php was restored.');

$moduleRoutesPosition = strpos($module, '$routeRegistrar->register');
$coreRoutesPosition = strpos($module, 'CoreWebRoutes::register($router)');
$assert(
    $moduleRoutesPosition !== false && $coreRoutesPosition !== false && $coreRoutesPosition > $moduleRoutesPosition,
    'Core not-found registration must happen after remaining legacy module route contributors.',
);

$symfonyRoutes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_spatial_manage:',
    'cos_web_spatial_edit_new:',
    'cos_web_spatial_edit:',
    'cos_web_spatial_save_new:',
    'cos_web_spatial_save:',
    'cos_web_spatial_upload:',
    'cos_web_spatial_external:',
    'cos_web_spatial_capture:',
    'cos_web_spatial_hotspot:',
    'cos_web_spatial_publish:',
    'cos_web_spatial_scene:',
] as $route) {
    $assert(str_contains($symfonyRoutes, $route), 'Canonical Symfony Spatial Web route is missing: ' . $route);
}
foreach ([
    'cos_web_spatial_save_new:',
    'cos_web_spatial_save:',
    'cos_web_spatial_upload:',
    'cos_web_spatial_external:',
    'cos_web_spatial_capture:',
    'cos_web_spatial_hotspot:',
    'cos_web_spatial_publish:',
] as $route) {
    $offset = strpos($symfonyRoutes, $route);
    $slice = $offset === false ? '' : substr($symfonyRoutes, $offset, 260);
    $assert(str_contains($slice, 'methods: [POST]'), 'Symfony Spatial mutation route must stay POST-only: ' . $route);
}

$errorController = $read('app/Interfaces/Web/Controller/ErrorController.php');
foreach ([
    "renderFrontendFailure(404",
    "'not_found'",
    "'/api/'",
    "return 'portal'",
    "return 'workspace'",
    "return 'public'",
    'public function notFoundAction(): ?ResponseInterface',
    'return $this->json([',
] as $needle) {
    $assert(str_contains($errorController, $needle), 'Canonical legacy 404 controller is missing behavior: ' . $needle);
}

$liveSmokePath = $root . '/tests/smoke/web_v013_live_routes.sh';
$assert(is_file($liveSmokePath), 'WEB V0.13 live routing smoke script is missing.');
$liveSmoke = (string) file_get_contents($liveSmokePath);
foreach (['/cabinet/index', '/admin/index', '/cabinet/telegramConnect', '/spatial/save', '/api/this-route-does-not-exist-v013', 'application/json'] as $needle) {
    $assert(str_contains($liveSmoke, $needle), 'WEB V0.13 live routing smoke is missing assertion: ' . $needle);
}

$runtimeWorkflow = $read('.github/workflows/diagnostic.yml');
$assert(str_contains($runtimeWorkflow, 'bash tests/smoke/web_v013_live_routes.sh'), 'AWS dev deploy must execute the WEB V0.13 live routing smoke.');

$documentation = $read('docs/architecture/web-v0.13.md');
foreach (['Router(false)', 'CoreWebRoutes', 'Symfony', 'application-wide 404', 'module route contributors', 'live routing smoke'] as $needle) {
    $assert(str_contains($documentation, $needle), 'WEB V0.13 documentation is missing contract: ' . $needle);
}

echo "WEB V0.13 explicit routing passed: core legacy routes remain explicit and Spatial Web ownership is canonical on Symfony.\n";
