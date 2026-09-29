<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Kernel\Module\KernelVersion;
if(version_compare(KernelVersion::VERSION,'0.8.10','<')) throw new RuntimeException('COS Kernel module readiness diagnostics require Kernel 0.8.10+.');

$diagnostic=(string)file_get_contents($root.'/app/Kernel/Module/ModuleReadinessDiagnostic.php');
foreach(['MigrationRunnerInterface','migrations->status()','UPGRADE_REQUIRED','SCHEMA_NOT_READY','SCHEMA_STATUS_UNAVAILABLE','DEPENDENCY_NOT_READY','DISABLED','READY'] as $needle){
    if(!str_contains($diagnostic,$needle)) throw new RuntimeException('Module readiness diagnostic is missing: '.$needle);
}
if(str_contains($diagnostic,'getMessage()')) throw new RuntimeException('Module readiness diagnostics must not expose raw migration/database errors.');

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['Kernel\\Module\\ModuleReadinessDiagnostic','Kernel\\Database\\MigrationRunnerInterface','Infrastructure\\Platform\\Persistence\\MySql\\Migration\\MigrationRunner'] as $needle){
    if(!str_contains($services,$needle)) throw new RuntimeException('Symfony module readiness composition is missing: '.$needle);
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
if(!str_contains($routes,'/api/v1/platform/modules/readiness')) throw new RuntimeException('Module readiness endpoint is not registered in Symfony.');

$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/PlatformModuleController.php');
$readinessStart=strpos($controller,'public function readiness()');
$installStart=strpos($controller,'public function install(', $readinessStart===false?0:$readinessStart);
if($readinessStart===false||$installStart===false) throw new RuntimeException('Readiness controller action is missing.');
$readiness=substr($controller,$readinessStart,$installStart-$readinessStart);
foreach(['context(true)','$this->readinessDiagnostic->diagnose'] as $needle){
    if(!str_contains($readiness,$needle)) throw new RuntimeException('Admin readiness action is missing: '.$needle);
}

$indexStart=strpos($controller,'public function index()');
if($indexStart===false||$readinessStart<=$indexStart) throw new RuntimeException('Cannot isolate normal module index action.');
$index=substr($controller,$indexStart,$readinessStart-$indexStart);
if(str_contains($index,'readinessDiagnostic')||str_contains($index,'MigrationRunner')) throw new RuntimeException('Hot module-context endpoint must not run readiness diagnostics.');

echo "COS Kernel V0.8.10 module readiness diagnostics architecture passed on Symfony.\n";
