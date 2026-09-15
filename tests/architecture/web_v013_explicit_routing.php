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
$spatialRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SpatialWebRoutes.php');

$coreRequired = [
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
];
foreach ($coreRequired as $pattern) {
    if (!str_contains($coreRoutes, "'" . $pattern . "'")) {
        throw new RuntimeException('WEB V0.13 core explicit route is missing: ' . $pattern);
    }
}

$spatialRequired = [
    '/spatial/manage',
    '/spatial/edit',
    '/spatial/edit/{id:[0-9]+}',
    '/spatial/save',
    '/spatial/save/{id:[0-9]+}',
    '/spatial/upload/{id:[0-9]+}',
    '/spatial/external/{id:[0-9]+}',
    '/spatial/capture/{id:[0-9]+}',
    '/spatial/hotspot/{id:[0-9]+}',
    '/spatial/publish/{id:[0-9]+}',
    '/spatial/scene/{slug:[A-Za-z0-9_-]+}',
];
foreach ($spatialRequired as $pattern) {
    if (!str_contains($spatialRoutes, "'" . $pattern . "'")) {
        throw new RuntimeException('WEB V0.13 Spatial explicit route is missing: ' . $pattern);
    }
}

foreach (['/:controller', '/:controller/:action', '/:controller/:action/:params'] as $defaultPattern) {
    if (str_contains($coreRoutes, $defaultPattern) || str_contains($spatialRoutes, $defaultPattern)) {
        throw new RuntimeException('Global Phalcon default-style route leaked into V0.13 declarations: ' . $defaultPattern);
    }
}

foreach ([
    '/cabinet/telegramConnect',
    '/cabinet/telegramDisconnect',
    '/admin/createUser',
    '/admin/updateUser/{id:[0-9]+}',
] as $mutationPattern) {
    if (!str_contains($coreRoutes, "addPost('" . $mutationPattern . "'")) {
        throw new RuntimeException('Core mutation route must stay POST-only: ' . $mutationPattern);
    }
}
foreach ([
    '/spatial/save',
    '/spatial/save/{id:[0-9]+}',
    '/spatial/upload/{id:[0-9]+}',
    '/spatial/external/{id:[0-9]+}',
    '/spatial/capture/{id:[0-9]+}',
    '/spatial/hotspot/{id:[0-9]+}',
    '/spatial/publish/{id:[0-9]+}',
] as $mutationPattern) {
    if (!str_contains($spatialRoutes, "addPost('" . $mutationPattern . "'")) {
        throw new RuntimeException('Spatial mutation route must stay POST-only: ' . $mutationPattern);
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
    "addGet('/spatial/manage', self::target('manage'))",
    "addGet('/spatial/edit/{id:[0-9]+}', self::target('edit')",
    "addGet('/spatial/scene/{slug:[A-Za-z0-9_-]+}', self::target('scene')",
] as $mapping) {
    if (!str_contains($spatialRoutes, $mapping)) {
        throw new RuntimeException('Spatial route target mapping is missing: ' . $mapping);
    }
}

foreach (['/cabinet/index', '/admin/index'] as $forbiddenAlias) {
    if (str_contains($coreRoutes, "'" . $forbiddenAlias . "'") || str_contains($spatialRoutes, "'" . $forbiddenAlias . "'")) {
        throw new RuntimeException('Implicit-style alias must not become explicit again: ' . $forbiddenAlias);
    }
}
if (!str_contains($coreRoutes, "\$router->notFound(\$web('error', 'notFound'))")) {
    throw new RuntimeException('Canonical application-wide not-found target is missing.');
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
foreach (['Router(false)', 'CoreWebRoutes', 'SpatialWebRoutes', 'application-wide 404', 'module route contributors', 'live routing smoke'] as $needle) {
    if (!str_contains($documentation, $needle)) {
        throw new RuntimeException('WEB V0.13 documentation is missing contract: ' . $needle);
    }
}

echo "WEB V0.13 explicit routing and 404 declaration contract passed.\n";
