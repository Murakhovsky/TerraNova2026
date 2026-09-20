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
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Bootstrap/VisualizationServices.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Phalcon SSR delivery restored: ' . $path);
}

foreach ([
    'symfony/src/Web/Visualization/ArchitectureExplorerController.php',
    'symfony/src/Web/Diagnostic/DiagnosticReportController.php',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony SSR controller depends on Phalcon: ' . $path);
}

$architecture = $read('symfony/src/Web/Visualization/ArchitectureExplorerController.php');
$assert(!str_contains($architecture, 'Infrastructure\\'), 'Visualization controller must remain Infrastructure-neutral.');
foreach ([
    'GraphProviderInterface',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    'private object $mapper',
    'public function index(',
    'public function graph(',
    'public function health(',
] as $needle) {
    $assert(str_contains($architecture, $needle), 'Symfony Architecture Explorer is missing: ' . $needle);
}

$diagnostic = $read('symfony/src/Web/Diagnostic/DiagnosticReportController.php');
foreach ([
    'DiagnosticRuntimeService',
    "render(\$request, 'diagnostic_report/show'",
    'portalNavigation',
] as $needle) {
    $assert(str_contains($diagnostic, $needle), 'Symfony Diagnostic report is missing: ' . $needle);
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_architecture:',
    'cos_web_architecture_graph:',
    'cos_web_architecture_health:',
    'cos_web_diagnostic_report:',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Canonical Symfony SSR route missing: ' . $needle);
}

$security = $read('symfony/config/packages/security.yaml');
foreach ([
    'cos/architecture',
    'diagnostics',
    "path: '^/cos/architecture(?:/|$)'",
    "path: '^/diagnostics/[A-Za-z0-9_.:-]{8,64}/report$'",
] as $needle) {
    $assert(str_contains($security, $needle), 'Symfony SSR security boundary missing: ' . $needle);
}

$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
$assert(str_contains($authenticator, "str_starts_with($path, '/cos/architecture')"), 'Architecture routes are not authenticated through the shared session bridge.');
$assert(str_contains($authenticator, "str_starts_with($path, '/diagnostics/')"), 'Diagnostic report is not authenticated through the shared session bridge.');

$kernelServices = $read('app/Bootstrap/KernelServices.php');
foreach (['cosArchitectureGraphProvider', 'cosCytoscapeGraphMapper'] as $legacy) {
    $assert(!str_contains($kernelServices, $legacy), 'Retired Phalcon Visualization composition restored: ' . $legacy);
}
$kernelConfig = $read('app/config/services_kernel.php');
$assert(!str_contains($kernelConfig, 'VisualizationServices.php'), 'Retired VisualizationServices bootstrap is still loaded.');

$module = $read('app/Interfaces/Web/Module.php');
$assert(!str_contains($module, 'VisualizationRoutes'), 'Legacy Web module still owns Visualization routes.');

$webServices = $read('app/Bootstrap/WebApplicationServices.php');
$assert(!str_contains($webServices, 'DiagnosticModuleRouteContributor'), 'Legacy Web composition still owns Diagnostic routes.');

$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(($manifest['contributions']['api_route_contributor_services'] ?? null) === [], 'Diagnostic manifest still contributes legacy Web routes.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach ([
        'location = /cos/architecture',
        'location ^~ /cos/architecture/',
        'location ^~ /diagnostics/',
    ] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host proxy does not route Symfony SSR surface in ' . $path . ': ' . $needle);
    }
}

echo "Symfony Visualization/Diagnostic SSR cutover boundary OK\n";
