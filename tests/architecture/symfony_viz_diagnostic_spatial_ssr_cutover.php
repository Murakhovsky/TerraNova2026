<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Controller/DiagnosticReportController.php',
    'app/Interfaces/Web/Controller/MethodologyStudioController.php',
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Interfaces/Web/Controller/SpatialController.php',
    'app/Interfaces/Web/Routing/SpatialWebRoutes.php',
] as $retired) {
    $assert(!file_exists($root . '/' . $retired), 'Retired Phalcon SSR delivery restored: ' . $retired);
}

$controllers = [
    'symfony/src/Web/Visualization/ArchitectureExplorerController.php' => [
        'GraphProviderInterface', 'GraphProjectionRegistryInterface', 'GraphHealthAnalyzerInterface', 'CytoscapeGraphMapper',
    ],
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php' => [
        'DiagnosticRuntimeService', 'DiagnosticMethodologyAccess', 'PhtmlRenderer',
    ],
    'symfony/src/Web/Spatial/SpatialPageController.php' => [
        'SpatialSceneInterface', 'UploadedFile', 'PhtmlRenderer',
    ],
    'symfony/src/Web/WorkspacePageContext.php' => [
        'TenantContextProviderInterface', 'LegacySessionReader', 'NavigationBuilder',
    ],
];
foreach ($controllers as $path => $needles) {
    $source = $read($path);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony SSR layer depends on Phalcon: ' . $path);
    foreach ($needles as $needle) {
        $assert(str_contains($source, $needle), 'Canonical Symfony SSR layer is missing dependency/contract ' . $needle . ' in ' . $path);
    }
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_architecture:',
    'cos_web_architecture_graph:',
    'cos_web_architecture_health:',
    'cos_web_diagnostic_report:',
    'cos_web_diagnostic_methodology_studio:',
    'cos_web_spatial_manage:',
    'cos_web_spatial_edit:',
    'cos_web_spatial_save:',
    'cos_web_spatial_upload:',
    'cos_web_spatial_publish:',
    'cos_web_spatial_scene:',
] as $route) {
    $assert(str_contains($routes, $route), 'Canonical Symfony SSR route is missing: ' . $route);
}

$security = $read('symfony/config/packages/security.yaml');
foreach ([
    'cos/architecture',
    'diagnostics',
    'admin/diagnostics/methodology-studio',
    'spatial',
    "^/spatial/scene/",
] as $needle) {
    $assert(str_contains($security, $needle), 'Symfony SSR security boundary is missing: ' . $needle);
}

$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
foreach (['/cos/architecture', '/diagnostics/', '/admin/diagnostics/methodology-studio', '/spatial/'] as $needle) {
    $assert(str_contains($authenticator, $needle), 'Legacy-session bridge does not cover migrated SSR path: ' . $needle);
}
$assert(str_contains($authenticator, "str_starts_with(\$path, '/spatial/scene/')"), 'Public Spatial scene must bypass session authentication.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach ([
        'location = /cos/architecture',
        'location ^~ /cos/architecture/',
        'location ^~ /diagnostics/',
        'location = /admin/diagnostics/methodology-studio',
        'location ^~ /spatial/',
        'SYMFONY_UPSTREAM',
    ] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host ingress cutover missing in ' . $path . ': ' . $needle);
    }
}

$webModule = $read('app/Interfaces/Web/Module.php');
$assert(!str_contains($webModule, 'VisualizationRoutes'), 'Legacy Web module restored Visualization route ownership.');
$assert(!str_contains($webModule, 'SpatialWebRoutes'), 'Legacy Web module restored Spatial route ownership.');

$webServices = $read('app/Bootstrap/WebApplicationServices.php');
$assert(!str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Legacy Web composition restored Diagnostic route contributor.');

$diagnosticManifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(
    ($diagnosticManifest['contributions']['api_route_contributor_services'] ?? []) === [],
    'Diagnostic domain manifest restored Phalcon delivery route contributions.',
);

echo "Visualization + Diagnostic + Spatial Symfony SSR cutover boundary OK\n";
