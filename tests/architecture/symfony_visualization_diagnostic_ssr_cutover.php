<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
    'app/Interfaces/Web/Controller/DiagnosticReportController.php',
    'app/Interfaces/Web/Controller/MethodologyStudioController.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Phalcon Visualization/Diagnostic delivery restored: ' . $path);
}

foreach ([
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php',
    'app/Kernel/Visualization/Graph/GraphPayloadMapperInterface.php',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony Web code depends on Phalcon: ' . $path);
}

$architecture = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach ([
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    'GraphPayloadMapperInterface',
    "['cos-architecture-explorer']",
] as $needle) {
    $assert(str_contains($architecture, $needle), 'Canonical Architecture page owner is missing: ' . $needle);
}
$assert(!str_contains($architecture, 'Infrastructure\\Visualization'), 'Architecture page controller bypasses the Kernel Visualization ports.');

$diagnostic = $read('symfony/src/Web/Diagnostic/DiagnosticPageController.php');
foreach ([
    'DiagnosticMethodologyAccess::VIEW',
    '$this->runtime->report(',
    "['diagnostics-methodology-studio']",
    '$this->navigation->portal($tenant)',
] as $needle) {
    $assert(str_contains($diagnostic, $needle), 'Canonical Diagnostic page owner is missing: ' . $needle);
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_architecture:',
    'cos_web_architecture_graph:',
    'cos_web_architecture_health:',
    'cos_web_diagnostic_methodology_studio:',
    'cos_web_diagnostic_report:',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Symfony Web route missing: ' . $needle);
}

$security = $read('symfony/config/packages/security.yaml');
foreach ([
    'cos/architecture',
    'admin/diagnostics/methodology-studio',
    'diagnostics',
] as $needle) {
    $assert(str_contains($security, $needle), 'Symfony security boundary missing: ' . $needle);
}

$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
foreach ([
    "str_starts_with(\$path, '/cos/architecture')",
    "\$path === '/admin/diagnostics/methodology-studio'",
    "str_starts_with(\$path, '/diagnostics/')",
] as $needle) {
    $assert(str_contains($authenticator, $needle), 'Legacy session bridge does not authenticate migrated HTML path: ' . $needle);
}

$frontendRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
$assert(!str_contains($frontendRoutes, '/admin/diagnostics/methodology-studio'), 'Phalcon FrontendRoutes retained Methodology Studio.');

$webModule = $read('app/Interfaces/Web/Module.php');
$assert(!str_contains($webModule, 'VisualizationRoutes'), 'Phalcon Web module retained Visualization route ownership.');

$webServices = $read('app/Bootstrap/WebApplicationServices.php');
$assert(!str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Legacy Web composition retained Diagnostic route contributor.');
$assert(!str_contains($webServices, 'diagnosticRouteContributor'), 'Legacy Web composition retained Diagnostic route service.');

$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(($manifest['contributions']['api_route_contributor_services'] ?? null) === [], 'Diagnostic module restored a Phalcon route contribution.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach ([
        'location = /cos/architecture',
        'location ^~ /cos/architecture/',
        'location = /admin/diagnostics/methodology-studio',
        'location ^~ /diagnostics/',
    ] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host ingress does not cut migrated Web route to Symfony: ' . $path . ' / ' . $needle);
    }
}

foreach (['docker/symfony/php/Dockerfile', 'docker/symfony/nginx/Dockerfile'] as $path) {
    $docker = $read($path);
    $assert(str_contains($docker, 'FROM node:22-alpine AS frontend'), 'Symfony image does not source-build Vite assets: ' . $path);
    $assert(str_contains($docker, 'RUN npm run build'), 'Symfony image does not execute Vite build: ' . $path);
    $assert(str_contains($docker, 'COPY --from=frontend /src/public/build/'), 'Symfony image does not consume source-built assets: ' . $path);
}

echo "Symfony Visualization/Diagnostic SSR cutover boundary OK\n";
