<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$routes=$read('symfony/config/routes.yaml');
foreach([
 'cos_api_v1_public_properties:','path: /api/v1/public/properties',
 'cos_api_v1_public_properties_featured:','path: /api/v1/public/properties/featured',
 'cos_api_v1_public_property:','App\\Http\\Api\\V1\\Controller\\PublicPropertyController'
] as $needle)$assert(str_contains($routes,$needle),'Public Property route contract missing: '.$needle);

$controller=$read('symfony/src/Http/Api/V1/Controller/PublicPropertyController.php');
foreach(['PublicPropertyReadService','properties->catalog','properties->featured','properties->show'] as $needle){
 $assert(str_contains($controller,$needle),'Public Property controller contract missing: '.$needle);
}

$repository=$read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php');
foreach(['p.organization_id = :organization_id','p.visibility = "public"','p.status IN ("published", "active")'] as $needle){
 $assert(str_contains($repository,$needle),'Public Property isolation missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
 'Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection:',
 "      \$config: '@cos.database.pdo'",
 'Domains\\Property\\Infrastructure\\ReadModel\\MySql\\MysqlPublicPropertyReadRepository:',
 'Domains\\Property\\Application\\Contract\\PublicPropertyReadRepositoryInterface:',
] as $needle)$assert(str_contains($services,$needle),'Public Property canonical wiring missing: '.$needle);
foreach(['legacy_cos.pdo','LegacyPropertyLocationReferenceAdapter'] as $forbidden)$assert(!str_contains($services,$forbidden),'Legacy Property dependency returned: '.$forbidden);

$security=$read('symfony/config/packages/security.yaml');
$assert(str_contains($security,"^/api/v1/public/properties(?:/|$)"),'Public Property API is not PUBLIC_ACCESS.');
$authenticator=$read('symfony/src/Security/SessionAuthenticator.php');
$assert(str_contains($authenticator,'#^/api/v1/public/properties(?:/|$)#'),'Session authenticator must bypass public Property reads.');

foreach(['app/Interfaces/Web/Routing/FrontendRoutes.php','app/Interfaces/Web/Controller/ApiController.php','app/bootstrap_web.php'] as $retired){
 $assert(!file_exists($root.'/'.$retired),'Retired Property transport returned: '.$retired);
}

$catalogController=$read('symfony/src/Web/Property/PublicPropertyCatalogController.php');
$catalogHandler=$read('symfony/src/Application/Property/Query/GetPublicPropertyCatalogQueryHandler.php');
$catalogView=$read('symfony/templates/experience/public/property_catalog.html.twig');
$homeView=$read('app/Interfaces/Web/View/index/index.phtml');
foreach(['GetPublicPropertyCatalogQuery','QueryBusInterface'] as $needle){
 $assert(str_contains($catalogController,$needle),'Catalog controller is not using canonical Application reads: '.$needle);
}
foreach(['PublicPropertyReadService','properties->catalog'] as $needle){
 $assert(str_contains($catalogHandler,$needle),'Catalog Query handler is not using canonical public reads: '.$needle);
}
$assert(str_contains($catalogView,'data-cos-public="property-catalog"'),'Catalog Twig surface is missing canonical public marker.');
$assert(!is_file($root.'/app/Interfaces/Web/View/property/catalog.phtml'),'Retired Catalog PHTML returned.');
$assert(str_contains($homeView,'api/v1/public/properties/featured'),'Homepage featured feed is not using canonical public reads.');

echo "Public Property Symfony read cutover boundary OK\n";
