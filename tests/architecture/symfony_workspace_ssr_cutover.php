<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'app/Interfaces/Web/Controller/SpatialController.php',
    'app/Interfaces/Web/Controller/DiagnosticReportController.php',
    'app/Interfaces/Web/Controller/MethodologyStudioController.php',
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
    'app/Interfaces/Web/Routing/SpatialWebRoutes.php',
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Bootstrap/SpatialModule.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Phalcon workspace SSR artifact restored: ' . $path);
}

$bootstrap = $read('app/bootstrap_web.php');
$assert(!str_contains($bootstrap, 'Bootstrap\\SpatialModule'), 'Phalcon web bootstrap restored the retired Spatial module.');

$module = $read('app/Interfaces/Web/Module.php');
foreach (['SpatialWebRoutes', 'VisualizationRoutes'] as $legacy) {
    $assert(!str_contains($module, $legacy), 'Phalcon Web module restored retired route owner: ' . $legacy);
}

$diagnosticManifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(
    ($diagnosticManifest['contributions']['api_route_contributor_services'] ?? []) === [],
    'Diagnostic manifest restored the retired Phalcon route contribution.',
);

$frontendRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
$assert(
    !str_contains($frontendRoutes, '/admin/diagnostics/methodology-studio'),
    'Phalcon FrontendRoutes restored Symfony-owned Methodology Studio.',
);

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_architecture:',
    'cos_web_architecture_graph:',
    'cos_web_architecture_health:',
    'cos_web_diagnostic_report:',
    'cos_web_diagnostic_methodology_studio:',
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
    $assert(str_contains($routes, $route), 'Canonical Symfony workspace route missing: ' . $route);
}

foreach ([
    'symfony/src/Web/Visualization/ArchitectureExplorerController.php' => [
        'GraphProviderInterface',
        'GraphProjectionRegistryInterface',
        'GraphMapperInterface',
        'GraphHealthAnalyzerInterface',
    ],
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php' => [
        'DiagnosticRuntimeService',
        'DiagnosticMethodologyAccess',
        'PhtmlRenderer',
    ],
    'symfony/src/Web/Spatial/SpatialPageController.php' => [
        'SpatialSceneInterface',
        'PhtmlRenderer',
        'public function manage(',
        'public function scene(',
    ],
] as $path => $needles) {
    $source = $read($path);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony workspace controller depends on Phalcon: ' . $path);
    foreach ($needles as $needle) {
        $assert(str_contains($source, $needle), 'Canonical workspace controller missing contract marker ' . $needle . ': ' . $path);
    }
}

$mapperContract = $read('app/Kernel/Visualization/Graph/GraphMapperInterface.php');
$mapper = $read('app/Infrastructure/Visualization/Cytoscape/CytoscapeGraphMapper.php');
$assert(str_contains($mapperContract, 'interface GraphMapperInterface'), 'Renderer-neutral graph mapper port is missing.');
$assert(str_contains($mapper, 'implements GraphMapperInterface'), 'Cytoscape adapter does not implement the graph mapper port.');

$security = $read('symfony/config/packages/security.yaml');
foreach ([
    '|cos/architecture|admin/diagnostics|diagnostics|spatial)',
    "'^/spatial/scene/[A-Za-z0-9_-]+$'",
    "'^/cos/architecture(?:/|$)'",
    "'^/admin/diagnostics(?:/|$)'",
    "'^/diagnostics(?:/|$)'",
    "'^/spatial(?:/|$)'",
] as $needle) {
    $assert(str_contains($security, $needle), 'Symfony workspace security ownership missing: ' . $needle);
}

$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
foreach (['/cos/architecture', '/admin/diagnostics', '/diagnostics', '/spatial'] as $path) {
    $assert(str_contains($authenticator, $path), 'Legacy session bridge does not cover Symfony workspace path: ' . $path);
}

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach ([
        'location ^~ /cos/architecture',
        'location ^~ /admin/diagnostics/',
        'location ^~ /diagnostics/',
        'location ^~ /spatial/',
    ] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host proxy does not cut workspace path to Symfony in ' . $path . ': ' . $needle);
    }
}

echo "Symfony workspace SSR retirement boundary OK\n";
