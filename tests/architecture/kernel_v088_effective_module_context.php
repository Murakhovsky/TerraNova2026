<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Kernel\Module\KernelVersion;
if(version_compare(KernelVersion::VERSION,'0.8.8','<')) throw new RuntimeException('COS Kernel effective module context requires Kernel 0.8.8+.');

$context=(string)file_get_contents($root.'/app/Kernel/Module/EffectiveModuleContext.php');
foreach(['ActiveModuleResolver','ModuleCapabilityRegistry','active_module_ids','active_capabilities','capabilitiesFor'] as $needle){
    if(!str_contains($context,$needle)) throw new RuntimeException('EffectiveModuleContext is missing canonical state: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['Kernel\\Module\\EffectiveModuleContext','Kernel\\Module\\ModuleCapabilityRegistry','Kernel\\Module\\ActiveModuleResolver'] as $needle){
    if(!str_contains($services,$needle)) throw new RuntimeException('Symfony effective-context composition is missing: '.$needle);
}

$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/PlatformModuleController.php');
foreach(['EffectiveModuleContext','$this->context->describe'] as $needle){
    if(!str_contains($controller,$needle)) throw new RuntimeException('Platform module API does not consume canonical effective context: '.$needle);
}
if(str_contains($controller,'array_map(')) throw new RuntimeException('Platform module controller still assembles module state itself.');

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
if(!str_contains($routes,'/api/v1/platform/modules')) throw new RuntimeException('Effective module context is not exposed through Symfony.');

echo "COS Kernel V0.8.8 effective module context architecture passed on Symfony.\n";
