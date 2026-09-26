<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$legacyFiles=[
    'app/Interfaces/Api/Controller/HealthController.php',
    'app/Interfaces/Api/Controller/PlatformModuleController.php',
    'app/Interfaces/Api/Controller/DiagnosticMethodologyController.php',
    'app/Interfaces/Api/Controller/DiagnosticMethodologyWorkbenchController.php',
    'app/Interfaces/Web/Routing/PlatformRoutes.php',
    'symfony/src/Controller/CoreHealthController.php',
    'symfony/src/Controller/OperationsReadController.php',
    'symfony/src/Controller/SecurityContextController.php',
];
foreach($legacyFiles as $path){
    if(is_file($root.'/'.$path)) throw new RuntimeException('Runtime cleanup regression: retired file restored: '.$path);
}

$legacyApiDirectory=$root.'/app/Interfaces/Api/Controller';
$remainingLegacyApi=array_values(array_map('basename',glob($legacyApiDirectory.'/*.php')?:[]));
sort($remainingLegacyApi);
if($remainingLegacyApi!==[]){
    throw new RuntimeException('Legacy Phalcon API controllers remain after Spatial cutover: '.implode(', ',$remainingLegacyApi));
}

$frontend=(string)file_get_contents($root.'/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach(['/api/health','/api/admin/diagnostics','diagnostic_methodology_workbench','diagnostic_methodology'] as $needle){
    if(str_contains($frontend,$needle)) throw new RuntimeException('Runtime cleanup regression: retired Phalcon route restored: '.$needle);
}
$webModule=(string)file_get_contents($root.'/app/Interfaces/Web/Module.php');
if(str_contains($webModule,'PlatformRoutes')) throw new RuntimeException('Runtime cleanup regression: PlatformRoutes returned to Phalcon Web module.');

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/api/v1/health','/api/v1/operations/actions','/api/v1/platform/modules','/api/v1/platform/modules/readiness','/api/v1/admin/diagnostics/packs','/api/v1/admin/diagnostics/permissions/matrix','/api/spatial/auth/token','/api/spatial/events'] as $path){
    if(!str_contains($routes,$path)) throw new RuntimeException('Canonical Symfony replacement missing: '.$path);
}
foreach(['/migration/','App\\Controller\\CoreHealthController','App\\Controller\\OperationsReadController','App\\Controller\\SecurityContextController'] as $needle){
    if(str_contains($routes,$needle)) throw new RuntimeException('Retired Symfony migration surface restored: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['App\\Controller\\CoreHealthController','App\\Controller\\OperationsReadController','App\\Controller\\SecurityContextController'] as $needle){
    if(str_contains($services,$needle)) throw new RuntimeException('Retired migration controller remains in Symfony DI: '.$needle);
}
if(!str_contains($services,"\$queue: '@Kernel\\Queue\\Contract\\JobQueueInterface'")){
    throw new RuntimeException('ApprovalService must enqueue approved actions through the canonical JobQueue.');
}

$security=(string)file_get_contents($root.'/symfony/config/packages/security.yaml');
$healthRule="- { path: '^/api/v1/health\$', roles: PUBLIC_ACCESS }";
$methodologyRule="- { path: '^/api/v1/admin/diagnostics(?:/|\$)', roles: IS_AUTHENTICATED_FULLY }";
$managerRule="- { path: '^/api/v1(?:/|\$)', roles: ROLE_MANAGER }";
foreach([$healthRule,$methodologyRule,$managerRule] as $rule){
    if(!str_contains($security,$rule)) throw new RuntimeException('Runtime cleanup security rule missing: '.$rule);
}
if(strpos($security,$healthRule)>strpos($security,$managerRule)||strpos($security,$methodologyRule)>strpos($security,$managerRule)){
    throw new RuntimeException('Specific health/methodology access rules must precede the /api/v1 manager catch-all.');
}

$authenticator=(string)file_get_contents($root.'/symfony/src/Security/LegacySessionAuthenticator.php');
if(!str_contains($authenticator,"\$path === '/api/v1/health'")){
    throw new RuntimeException('Public health probe is still intercepted by the legacy-session authenticator.');
}

foreach(['methodology_studio_v054.js','methodology_studio_v055.js'] as $asset){
    $source=(string)file_get_contents($root.'/symfony/assets/islands/'.$asset);
    if(str_contains($source,'/api/admin/diagnostics')) throw new RuntimeException('Frontend still calls retired Methodology API: '.$asset);
    if(!str_contains($source,'/api/v1/admin/diagnostics')) throw new RuntimeException('Frontend lacks canonical Methodology API: '.$asset);
}

$devDeploy=(string)file_get_contents($root.'/deploy/dev.sh');
$httpProxy=(string)file_get_contents($root.'/deploy/configure-company-os-http.sh');
$tlsProxy=(string)file_get_contents($root.'/deploy/configure-dev-tls.sh');
$symfonyDeploy=(string)file_get_contents($root.'/deploy/symfony-dev.sh');
if(!str_contains($devDeploy,'bash deploy/symfony-dev.sh')) throw new RuntimeException('Canonical Symfony runtime is not part of DEV deployment.');
foreach([$httpProxy,$tlsProxy] as $proxy){
    if(!str_contains($proxy,'location ^~ /api/v1/')||!str_contains($proxy,'location ^~ /api/spatial/')||!str_contains($proxy,'127.0.0.1:8081')){
        throw new RuntimeException('Host proxy does not cut /api/v1/* and /api/spatial/* over to Symfony.');
    }
}
foreach(['cos_symfony_app','GRANT SELECT, INSERT, UPDATE, DELETE ON'] as $needle){
    if(!str_contains($symfonyDeploy,$needle)) throw new RuntimeException('Symfony DML application account contract missing: '.$needle);
}

echo "Runtime cleanup slice 3 irreversible boundary OK\n";
