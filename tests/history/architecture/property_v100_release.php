<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$module=require $root.'/app/Domains/Property/module.php';
$assert(($module['version']??null)==='1.0.0','Property V1 release requires module version 1.0.0.');
$assert(($module['schema_version']??null)==='1.0.0','Property V1 release requires schema version 1.0.0.');
foreach(['property.runtime.canonical','property.business.cutover','property.read.canonical','property.v1'] as $capability){
    $assert(in_array($capability,$module['contributions']['capabilities']??[],true),'Property V1 capability missing: '.$capability);
}

$projection=$read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyProjection.php');
$assert(str_contains($projection,'INSERT INTO tn_property_public_read_model'),'Property V1 public projection is missing.');
$assert(!str_contains($projection,'SELECT * FROM tn_properties'),'Property V1 public read model still copies legacy projection.');
$assert(!str_contains($projection,'FROM tn_properties'),'Property V1 canonical projection still reads legacy tn_properties.');

$write=$read('app/Domains/Property/Infrastructure/Persistence/MySql/Management/CanonicalPropertyManagementWriteRepository.php');
$preflight=strpos($write,'if ($files !== [])');
$create=strpos($write,'$this->runtime->createBundle');
$assert($preflight!==false && $create!==false && $preflight<$create,'Property V1 must reject legacy media before canonical mutation.');

$assert(!is_file($root.'/app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyManagementRepository.php'),'Retired legacy management repository returned.');
$assert(!is_file($root.'/app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyTourPublisher.php'),'Retired legacy Spatial writer returned.');

$migration='app/migrations/20260928_000117_property_v100_hardening.sql';
$assert(in_array($migration,$module['contributions']['migration_files']??[],true),'Property V1 migration 000117 missing.');

echo "Property V1 release architecture: OK\n";
