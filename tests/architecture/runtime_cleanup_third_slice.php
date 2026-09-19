<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$legacyFiles=[
    'app/Interfaces/Api/Controller/HealthController.php',
    'app/Interfaces/Api/Controller/PlatformModuleController.php',
    'app/Interfaces/Api/Controller/DiagnosticMethodologyController.php',
    'app/Interfaces/Api/Controller/DiagnosticMethodologyWorkbenchController.php',
    'app/Interfaces/Web/Routing/PlatformRoutes.php',
];
foreach($legacyFiles as $path){
    if(is_file($root.'/'.$path)) throw new RuntimeException('Runtime cleanup regression: retired file restored: '.$path);
}

$frontend=(string)file_get_contents($root.'/app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach(['/api/health','/api/admin/diagnostics','diagnostic_methodology_workbench','diagnostic_methodology'] as $needle){
    if(str_contains($frontend,$needle)) throw new RuntimeException('Runtime cleanup regression: retired Phalcon route restored: '.$needle);
}
$webModule=(string)file_get_contents($root.'/app/Interfaces/Web/Module.php');
if(str_contains($webModule,'PlatformRoutes')) throw new RuntimeException('Runtime cleanup regression: PlatformRoutes returned to Phalcon Web module.');

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/api/v1/health','/api/v1/platform/modules','/api/v1/platform/modules/readiness','/api/v1/admin/diagnostics/packs','/api/v1/admin/diagnostics/permissions/matrix'] as $path){
    if(!str_contains($routes,$path)) throw new RuntimeException('Canonical Symfony replacement missing: '.$path);
}

foreach(['methodology-studio-v054.js','methodology-studio-v055.js'] as $asset){
    $source=(string)file_get_contents($root.'/frontend/features/diagnostics/'.$asset);
    if(str_contains($source,'/api/admin/diagnostics')) throw new RuntimeException('Frontend still calls retired Methodology API: '.$asset);
    if(!str_contains($source,'/api/v1/admin/diagnostics')) throw new RuntimeException('Frontend lacks canonical Methodology API: '.$asset);
}

$devDeploy=(string)file_get_contents($root.'/deploy/dev.sh');
$httpProxy=(string)file_get_contents($root.'/deploy/configure-company-os-http.sh');
$tlsProxy=(string)file_get_contents($root.'/deploy/configure-dev-tls.sh');
if(!str_contains($devDeploy,'bash deploy/symfony-dev.sh')) throw new RuntimeException('Canonical Symfony runtime is not part of DEV deployment.');
foreach([$httpProxy,$tlsProxy] as $proxy){
    if(!str_contains($proxy,'location ^~ /api/v1/')||!str_contains($proxy,'127.0.0.1:8081')){
        throw new RuntimeException('Host proxy does not cut /api/v1/* over to Symfony.');
    }
}

echo "Runtime cleanup slice 3 irreversible boundary OK\n";
