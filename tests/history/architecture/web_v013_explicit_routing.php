<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$services = (string) file_get_contents($root . '/app/config/services_web.php');
foreach (['new Router(false)', 'removeExtraSlashes(true)'] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('WEB V0.13 router configuration is missing: ' . $needle);
    }
}
if (preg_match('/new\s+Router\s*\(\s*\)/', $services) === 1) {
    throw new RuntimeException('Phalcon default routes must stay disabled.');
}

$coreRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/CoreWebRoutes.php');
$symfonyRoutes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$spatialController = (string) file_get_contents($root . '/symfony/src/Web/Spatial/SpatialPageController.php');
$spatialManageController = (string) file_get_contents($root . '/symfony/src/Web/Spatial/SpatialManageController.php');

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
    if (!str_contains($coreRoutes, "'" . $pattern . "'")) {
        throw new RuntimeException('WEB V0.13 core explicit route is missing: ' . $pattern);
    }
}

$spatialRequired = [
    'path: /spatial/manage',
    'path: /spatial/edit',
    'path: /spatial/edit/{id}',
    'path: /spatial/save',
    'path: /spatial/save/{id}',
    'path: /spatial/upload/{id}',
    'path: /spatial/external/{id}',
    'path: /spatial/capture/{id}',
    'path: /spatial/hotspot/{id}',
    'path: /spatial/publish/{id}',
    'path: /spatial/scene/{slug}',
];
foreach ($spatialRequired as $pattern) {
    if (!str_contains($symfonyRoutes, $pattern)) {
        throw new RuntimeException('WEB V0.13 Spatial Symfony route is missing: ' . $pattern);
    }
}

foreach (['/:controller', '/:controller/:action', '/:controller/:action/:params'] as $defaultPattern) {
    if (str_contains($coreRoutes, $defaultPattern)) {
        throw new RuntimeException('Global Phalcon default-style route leaked into V0.13 declarations: ' . $defaultPattern);
    }
}

foreach ([
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
] as $mutationPattern) {
    if (!str_contains($coreRoutes, "addPost('" . $mutationPattern . "'")) {
        throw new RuntimeException('Core mutation route must stay POST-only: ' . $mutationPattern);
    }
}
foreach ([
    'cos_web_spatial_save_new:',
    'cos_web_spatial_save:',
    'cos_web_spatial_upload:',
    'cos_web_spatial_external:',
    'cos_web_spatial_capture:',
    'cos_web_spatial_hotspot:',
    'cos_web_spatial_publish:',
] as $routeName) {
    $position = strpos($symfonyRoutes, $routeName);
    if ($position === false || !str_contains(substr($symfonyRoutes, $position, 240), 'methods: [POST]')) {
        throw new RuntimeException('Spatial mutation route must stay POST-only: ' . $routeName);
    }
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
    if (!str_contains($coreRoutes, $mapping)) {
        throw new RuntimeException('Core route target mapping is missing: ' . $mapping);
    }
}
foreach ([
    'SpatialManageController::index',
    'SpatialPageController::edit',
    'SpatialPageController::scene',
] as $mapping) {
    if (!str_contains($symfonyRoutes, $mapping)) {
        throw new RuntimeException('Spatial Symfony route target mapping is missing: ' . $mapping);
    }
}

foreach (['/cabinet/index', '/admin/index'] as $forbiddenAlias) {
    if (str_contains($coreRoutes, "'" . $forbiddenAlias . "'")) {
        throw new RuntimeException('Implicit-style alias must not become explicit again: ' . $forbiddenAlias);
    }
}
if (!str_contains($coreRoutes, "\$router->notFound(\$web('error', 'notFound'))")) {
    throw new RuntimeException('Canonical application-wide not-found target is missing.');
}

$module = (string) file_get_contents($root . '/app/Interfaces/Web/Module.php');
if (!str_contains($module, 'CoreWebRoutes::register($router)')) {
    throw new RuntimeException('Web module is missing explicit Core route registration.');
}
if (str_contains($module, 'SpatialWebRoutes')) {
    throw new RuntimeException('Retired Spatial Phalcon route ownership was restored.');
}
if (file_exists($root . '/app/Interfaces/Web/Routing/SpatialWebRoutes.php')
    || file_exists($root . '/app/Interfaces/Web/Controller/SpatialController.php')) {
    throw new RuntimeException('Retired Spatial Phalcon Web delivery was restored.');
}
foreach (['SpatialSceneInterface', 'public function scene('] as $needle) {
    if (!str_contains($spatialController, $needle)) {
        throw new RuntimeException('Canonical Symfony Spatial page controller is missing: ' . $needle);
    }
}
foreach (['GetSpatialManageQuery','PageArchetype::MapSpatial'] as $needle) {
    if (!str_contains($spatialManageController,$needle)) {
        throw new RuntimeException('Canonical Spatial Manage controller is missing: '.$needle);
    }
}
$moduleRoutesPosition = strpos($module, '$routeRegistrar->register');
$coreRoutesPosition = strpos($module, 'CoreWebRoutes::register($router)');
if ($moduleRoutesPosition === false || $coreRoutesPosition === false || $coreRoutesPosition < $moduleRoutesPosition) {
    throw new RuntimeException('Core not-found registration must happen after module route contributors.');
}

$errorController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/ErrorController.php');
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
    if (!str_contains($errorController, $needle)) {
        throw new RuntimeException('Canonical 404 controller is missing behavior: ' . $needle);
    }
}

$liveSmokePath = $root . '/tests/smoke/web_v013_live_routes.sh';
if (!is_file($liveSmokePath)) {
    throw new RuntimeException('WEB V0.13 live routing smoke script is missing.');
}
$liveSmoke = (string) file_get_contents($liveSmokePath);
foreach (['/cabinet/index', '/admin/index', '/cabinet/telegramConnect', '/spatial/save', '/api/this-route-does-not-exist-v013', 'application/json'] as $needle) {
    if (!str_contains($liveSmoke, $needle)) {
        throw new RuntimeException('WEB V0.13 live routing smoke is missing assertion: ' . $needle);
    }
}
$runtimeWorkflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
if (!str_contains($runtimeWorkflow, 'bash tests/smoke/web_v013_live_routes.sh')) {
    throw new RuntimeException('AWS dev deploy must execute the WEB V0.13 live routing smoke.');
}

$documentation = (string) file_get_contents($root . '/docs/architecture/web-v0.13.md');
foreach (['Router(false)', 'CoreWebRoutes', 'Symfony Spatial', 'application-wide 404', 'module route contributors', 'live routing smoke'] as $needle) {
    if (!str_contains($documentation, $needle)) {
        throw new RuntimeException('WEB V0.13 documentation is missing contract: ' . $needle);
    }
}

echo "WEB V0.13 explicit routing and 404 declaration contract passed.\n";
