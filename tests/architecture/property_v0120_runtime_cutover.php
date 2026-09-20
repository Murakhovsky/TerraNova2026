<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$module=require $root.'/app/Domains/Property/module.php';
$assert(($module['version']??null)==='0.12.0','Property manifest must declare V0.12.0.');
$assert(in_array('property.runtime.canonical',$module['contributions']['capabilities']??[],true),'Canonical runtime capability is missing.');

$services=$read('symfony/config/services.yaml');
foreach([
    'Domains\\Property\\Infrastructure\\Persistence\\MySql\\MysqlPropertyCanonicalRuntimeRepository:',
    'Domains\\Property\\Infrastructure\\Persistence\\MySql\\MysqlPropertyProjection:',
    'Domains\\Property\\Application\\Contract\\PropertyProjectionInterface:',
    'App\\Infrastructure\\Persistence\\MySql\\MysqlPropertyLocationReference:',
    "@cos.database.pdo",
] as $needle)$assert(str_contains($services,$needle),'Canonical Property Symfony wiring missing: '.$needle);
foreach(['legacy_cos.pdo','LegacyPropertyLocationReferenceAdapter','MysqlPropertyCompatibilityProjection','PropertyCompatibilityProjectionInterface'] as $forbidden){
    $assert(!str_contains($services,$forbidden),'Retired Property compatibility wiring returned: '.$forbidden);
}

$runtime=$read('app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php');
$repository=$read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyCanonicalRuntimeRepository.php');
foreach([$runtime,$repository] as $canonical)$assert(!str_contains($canonical,'tn_properties'),'Canonical aggregate repository must not use public projection storage.');
foreach(['PropertyDomainEvents::','InventoryDomainEvents::','ListingDomainEvents::','events->publish'] as $needle){
    $assert(str_contains($runtime,$needle),'Canonical runtime event path missing: '.$needle);
}

$projection=$read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyProjection.php');
foreach(['INSERT INTO tn_properties','UPDATE tn_properties','LocationReferenceInterface'] as $needle){
    $assert(str_contains($projection,$needle),'Property read projection contract missing: '.$needle);
}
$assert(!preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+tn_locations/i',$projection),'Property projection must cross the Location reference port.');

$controller=$read('symfony/src/Http/Api/V1/Controller/PropertyController.php');
foreach(['CommandBusInterface','QueryBusInterface','SessionCsrfValidator','ActiveModuleResolver'] as $needle){
    $assert(str_contains($controller,$needle),'Canonical Symfony Property API boundary missing: '.$needle);
}

foreach(['app/bootstrap_web.php','app/Bootstrap/PropertyServices.php','app/Bootstrap/WebApplicationServices.php','app/Interfaces/Web/Module.php'] as $retired){
    $assert(!file_exists($root.'/'.$retired),'Retired Property/Phalcon composition returned: '.$retired);
}

echo "Property V0.12 canonical runtime architecture: OK\n";
