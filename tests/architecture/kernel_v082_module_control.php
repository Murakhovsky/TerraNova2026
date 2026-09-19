<?php
declare(strict_types=1);

use Kernel\Module\KernelVersion;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
if(version_compare(KernelVersion::VERSION,'0.8.2','<')) throw new RuntimeException('COS Kernel module control contract requires Kernel 0.8.2+.');

$control=(string)file_get_contents($root.'/app/Kernel/Module/ModuleControlService.php');
foreach(['AuditEntry','EventBus','TransactionManagerInterface','platform.module.lifecycle_changed',"'MODULE_LIFECYCLE'"] as $boundary){
    if(!str_contains($control,$boundary)) throw new RuntimeException('Module control boundary is missing: '.$boundary);
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/api/v1/platform/modules','/install','/upgrade','/enable','/disable','/uninstall'] as $route){
    if(!str_contains($routes,$route)) throw new RuntimeException('Symfony Platform module route is missing: '.$route);
}

$controllerPath=$root.'/symfony/src/Http/Api/V1/Controller/PlatformModuleController.php';
if(!is_file($controllerPath)) throw new RuntimeException('Canonical Symfony PlatformModuleController is missing.');
$controller=(string)file_get_contents($controllerPath);
foreach(['ModuleControlService','TenantPermissions::ADMIN','LegacySessionCsrfValidator','_cos_correlation_id'] as $boundary){
    if(!str_contains($controller,$boundary)) throw new RuntimeException('Platform module controller boundary is missing: '.$boundary);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['Kernel\\Module\\ModuleControlService','Kernel\\Module\\ModuleTenantProvisioner','Domains\\Sales\\Bootstrap\\SalesModuleConfigurationProvisioner','Domains\\Property\\Bootstrap\\PropertyModuleConfigurationProvisioner'] as $needle){
    if(!str_contains($services,$needle)) throw new RuntimeException('Symfony module-control composition is missing: '.$needle);
}

foreach([
    'app/Interfaces/Api/Controller/PlatformModuleController.php',
    'app/Interfaces/Web/Routing/PlatformRoutes.php',
] as $legacy){
    if(is_file($root.'/'.$legacy)) throw new RuntimeException('Retired Phalcon module-control transport restored: '.$legacy);
}

echo "COS Kernel module control plane is canonical on Symfony.\n";
