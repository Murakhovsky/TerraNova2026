<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$module=require $root.'/app/Domains/Property/module.php';
$assert(($module['version']??null)==='0.13.0','Property V0.13 hardening manifest missing.');
$assert(in_array('property.read.canonical',$module['contributions']['capabilities']??[],true),'Canonical Property read capability missing.');

$migration=$read('app/migrations/20260927_000063_property_v0130_v1_hardening.sql');
foreach(['tn_property_public_read_model','CREATE TABLE IF NOT EXISTS','INSERT IGNORE'] as $needle){
    $assert(str_contains($migration,$needle),'V0.13 public read projection migration missing: '.$needle);
}

foreach([
    'app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php',
    'app/Domains/Property/Infrastructure/ReadModel/MySql/CatalogService.php',
] as $path){
    $source=$read($path);
    $assert(str_contains($source,'tn_property_public_read_model'),'Canonical public read model not used: '.$path);
    $assert(!str_contains($source,'tn_properties'),'Legacy tn_properties still drives public business reads: '.$path);
}

$projection=$read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyProjection.php');
$assert(str_contains($projection,'syncPublicReadModel'),'Canonical projection must refresh the isolated public read model.');
$assert(str_contains($projection,'REPLACE INTO tn_property_public_read_model'),'Canonical public projection refresh missing.');

foreach([
    'app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php',
    'app/Domains/Property/Infrastructure/Persistence/MySql/Management/CanonicalPropertyManagementWriteRepository.php',
    'app/Domains/Property/Infrastructure/Persistence/MySql/Management/CanonicalPropertyManagementWorkflowRepository.php',
] as $path){
    $source=$read($path);
    $assert(str_contains($source,'PropertyProjectionInterface $compatibility'),'Compatibility projection dependency must be explicit: '.$path);
    $assert(!str_contains($source,'PropertyProjectionInterface $projection'),'Ambiguous projection dependency returned: '.$path);
}

echo "Property V0.13 V1 hardening boundary: OK\n";
