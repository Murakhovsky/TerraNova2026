<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition) throw new RuntimeException($message);
};

foreach([
    'app/Interfaces/Web/Visualization/Controller/ArchitectureExplorerController.php',
    'app/Interfaces/Web/Routing/VisualizationRoutes.php',
    'app/Interfaces/Web/Controller/MethodologyStudioController.php',
    'app/Interfaces/Web/Controller/DiagnosticReportController.php',
    'app/Interfaces/Web/Routing/DiagnosticRoutes.php',
    'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
    'app/Interfaces/Web/Controller/SpatialController.php',
    'app/Interfaces/Web/Routing/SpatialWebRoutes.php',
] as $path){
    $assert(!file_exists($root.'/'.$path),'Retired Phalcon Web surface restored: '.$path);
}

$routes=$read('symfony/config/routes.yaml');
foreach([
    'cos_web_architecture:',
    'cos_web_architecture_graph:',
    'cos_web_architecture_health:',
    'cos_web_diagnostic_methodology:',
    'cos_web_diagnostic_report:',
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
] as $needle){
    $assert(str_contains($routes,$needle),'Canonical Symfony Web route missing: '.$needle);
}

foreach([
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php',
    'symfony/src/Web/Spatial/SpatialPageController.php',
] as $path){
    $source=$read($path);
    $assert(!str_contains($source,'Phalcon\\'),'Canonical Symfony Web controller depends on Phalcon: '.$path);
}

$visualization=$read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach(['GraphProviderInterface','GraphProjectionRegistryInterface','GraphHealthAnalyzerInterface','GraphMapperInterface'] as $needle){
    $assert(str_contains($visualization,$needle),'Visualization Web contract missing: '.$needle);
}
$assert(!str_contains($visualization,'Infrastructure\\Visualization'),'Visualization Web controller bypasses Kernel contracts.');

$diagnostic=$read('symfony/src/Web/Diagnostic/DiagnosticPageController.php');
foreach(['DiagnosticRuntimeService','DiagnosticMethodologyAccess::VIEW','diagnostics-methodology-studio','ActiveModuleResolver','modules->isEnabled','diagnosticEnabled'] as $needle){
    $assert(str_contains($diagnostic,$needle),'Diagnostic Web contract missing: '.$needle);
}

$spatial=$read('symfony/src/Web/Spatial/SpatialPageController.php');
foreach(['SpatialSceneInterface','public function manage(','public function upload(','public function scene('] as $needle){
    $assert(str_contains($spatial,$needle),'Spatial Web contract missing: '.$needle);
}

$security=$read('symfony/config/packages/security.yaml');
foreach([
    'cos/architecture',
    'admin/diagnostics',
    'diagnostics',
    'spatial',
    '^/spatial/scene/[A-Za-z0-9_-]+$',
] as $needle){
    $assert(str_contains($security,$needle),'Symfony Web security boundary missing: '.$needle);
}

$authenticator=$read('symfony/src/Security/LegacySessionAuthenticator.php');
$assert(str_contains($authenticator,"str_starts_with(\$path, '/spatial')"),'Protected Spatial Web paths are not bridged through session auth.');
$assert(str_contains($authenticator,"preg_match('#^/spatial/scene/"),'Public Spatial scene exclusion is missing.');

foreach(['deploy/configure-company-os-http.sh','deploy/configure-dev-tls.sh'] as $path){
    $proxy=$read($path);
    foreach([
        'location = /cos/architecture {',
        'location ^~ /cos/architecture/ {',
        'location ^~ /admin/diagnostics/ {',
        'location ^~ /diagnostics/ {',
        'location ^~ /spatial/ {',
    ] as $needle){
        $assert(str_contains($proxy,$needle),'Host ingress cutover missing in '.$path.': '.$needle);
    }
}

$module=$read('app/Interfaces/Web/Module.php');
foreach(['VisualizationRoutes','SpatialWebRoutes'] as $legacy){
    $assert(!str_contains($module,$legacy),'Phalcon Web module restored retired route owner: '.$legacy);
}

$manifest=require $root.'/app/Domains/Diagnostic/module.php';
$assert(($manifest['contributions']['api_route_contributor_services']??[])===[],'Diagnostic manifest restored Phalcon Web route contribution.');

echo "Symfony Visualization, Diagnostic and Spatial Web cutover boundary OK\n";
