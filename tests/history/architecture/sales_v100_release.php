<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$module=require $root.'/app/Domains/Sales/module.php';
$assert(($module['version']??null)==='1.0.0','Sales V1 release requires module version 1.0.0.');
$assert(($module['schema_version']??null)==='0.8.6','Sales V1 must preserve the proven V0.8.6 persistence schema.');
$assert(($module['enabled_by_default']??false)===true,'Sales V1 must remain enabled by default.');
$assert(($module['contributions']['runtime_module_service']??null)==='salesDomainModule','Sales V1 runtime module is missing.');
$migration='app/migrations/20260928_000118_sales_v100_release.sql';
$assert(in_array($migration,$module['contributions']['migration_files']??[],true),'Sales V1 lifecycle migration is not declared.');
$sql=$read($migration);
foreach(["module_id='sales'","installed_version='1.0.0'","installed_version='0.8.6'","schema_version='0.8.6'"] as $needle)$assert(str_contains($sql,$needle),'Sales V1 lifecycle migration missing: '.$needle);
foreach(['tests/architecture/sales_domain_boundaries.php','tests/architecture/sales_diagnostics_boundaries.php','tests/unit/sales_domain_foundation.php','tests/unit/sales_runtime_contract.php','tests/unit/sales_v086_hardening.php','tests/smoke/deterministic_sales_processes.php'] as $gate)$assert(is_file($root.'/'.$gate),'Sales V1 release gate is missing: '.$gate);
$workflow=$read('.github/workflows/sales.yml');
foreach(['branches: [main]','sales_domain_boundaries.php','sales_diagnostics_boundaries.php','web_experience_wave13_sales_complete.php','sales_v100_release.php','sales_domain_foundation.php','sales_runtime_contract.php','sales_v086_hardening.php','deterministic_sales_processes.php'] as $needle)$assert(str_contains($workflow,$needle),'Sales V1 CI is missing release gate: '.$needle);
$assert(!str_contains($workflow,'branches: [COS]'),'Sales V1 CI must not target the retired COS branch.');
echo "Sales V1 release architecture: OK\\n";
