<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.25.0','Growth V0.25 manifest version must be 0.25.0.');
$assert(($manifest['schema_version']??null)==='0.22.0','Growth V0.25 must keep schema version 0.22.0.');
$assert(in_array('growth.handoff.target.service',$manifest['contributions']['capabilities']??[],true),'Growth Service handoff capability missing.');

$migration='app/migrations/20260923_000090_growth_v0250_service_handoff_adapter.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.25 lifecycle migration missing.');
$sql=$read($migration);
foreach(["installed_version='0.25.0'","installed_version='0.24.0'","schema_version='0.22.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.25 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.25 migration must remain schema-neutral.');
}

$adapter=$read('app/Domains/Growth/Infrastructure/Handoff/ServiceGrowthHandoffTarget.php');
foreach([
    'GrowthHandoffTargetInterface','ServiceApplicationBoundary','ActiveModuleResolver',
    "return 'service'","isEnabled($handoff->organizationId,'service')",'createRequest(',
    "'requester_ref'=>"."'growth:'","'service_request'",
] as $needle){
    $assert(str_contains($adapter,$needle),'Growth Service handoff adapter missing: '.$needle);
}
foreach([
    'PDO','ServiceRepositoryInterface','tn_service_','createTicket(','assignTicket(','setSla(',
    'escalate(','resolve(','close('
] as $forbidden){
    $assert(!str_contains($adapter,$forbidden),'Growth Service handoff adapter crossed target boundary: '.$forbidden);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthHandoffTargetInterface','growth.handoff_target','ServiceGrowthHandoffTarget',
    'ServiceApplicationBoundary','ActiveModuleResolver',
] as $needle){
    $assert(str_contains($services,$needle),'Growth Service handoff DI missing: '.$needle);
}

$registry=$read('app/Domains/Growth/Application/Service/GrowthHandoffTargetRegistry.php');
$assert(!str_contains($registry,'service'),'Growth handoff registry must remain generic and discover Service through tagged target port.');

echo "Growth V0.25 Service Handoff Adapter architecture: OK\n";
