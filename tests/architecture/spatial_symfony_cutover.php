<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition) throw new RuntimeException($message);
};

$legacyController=$root.'/app/Interfaces/Api/Controller/SpatialController.php';
$assert(!is_file($legacyController),'Retired Phalcon Spatial API controller was restored.');

$assert(!is_file($root.'/app/Bootstrap/SpatialModule.php'),'Retired Bootstrap\\SpatialModule was restored.');
$assert(!is_file($root.'/app/Interfaces/Web/Controller/SpatialController.php'),'Retired Phalcon Spatial Web controller was restored.');
$assert(!is_file($root.'/app/Interfaces/Web/Routing/SpatialWebRoutes.php'),'Retired Phalcon Spatial Web routes were restored.');

$webController=$read('symfony/src/Web/Spatial/SpatialPageController.php');
foreach(['SpatialSceneInterface','public function manage(','public function edit(','public function save(','public function upload(','public function scene('] as $needle){
    $assert(str_contains($webController,$needle),'Canonical Symfony Spatial Web controller missing: '.$needle);
}
$assert(!str_contains($webController,'Phalcon\\'),'Canonical Symfony Spatial Web controller depends on Phalcon.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    '/api/spatial/auth/token',
    '/api/spatial/scenes/{publicId}',
    '/api/spatial/scenes',
    '/api/spatial/scenes/{id}/assets',
    '/api/spatial/scenes/{id}/external-assets',
    '/api/spatial/scenes/{id}/captures',
    '/api/spatial/scenes/{id}/hotspots',
    '/api/spatial/scenes/{id}/publish',
    '/api/spatial/jobs/{publicId}',
    '/api/spatial/events',
    'App\\Http\\Api\\Spatial\\SpatialController',
] as $needle){
    $assert(str_contains($routes,$needle),'Canonical Symfony Spatial route missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection',
    'Infrastructure\\Media\\SpatialAssetService',
    'Domains\\Spatial\\Infrastructure\\Persistence\\MySql\\MysqlSpatialSceneRepository',
    'Domains\\Spatial\\Application\\Service\\SpatialSceneService',
    'Infrastructure\\Spatial\\SpatialProcessingService',
    'Domains\\Property\\Infrastructure\\Spatial\\CanonicalPropertyTourPublisher',
    'App\\Infrastructure\\Spatial\\SpatialTokenIssuer',
    'App\\Security\\SpatialBearerAuthenticator',
] as $needle){
    $assert(str_contains($services,$needle),'Symfony Spatial composition missing: '.$needle);
}

$security=$read('symfony/config/packages/security.yaml');
foreach([
    '^/api/spatial/auth/token$',
    '^/api/spatial/scenes/[A-Za-z0-9-]+$',
    '^/api/spatial/events$',
    '^/api/spatial(?:/|$)',
    'App\\Security\\SpatialBearerAuthenticator',
] as $needle){
    $assert(str_contains($security,$needle),'Symfony Spatial security boundary missing: '.$needle);
}

$compose=$read('docker-compose.symfony.yml');
foreach([
    'SPATIAL_JWT_SECRET',
    'SPATIAL_MAX_UPLOAD_BYTES',
    'spatial_uploads:/var/www/html/public/uploads/spatial',
    'spatial_uploads:/var/www/html/symfony/public/uploads/spatial:ro',
] as $needle){
    $assert(str_contains($compose,$needle),'Spatial runtime/volume contract missing: '.$needle);
}

foreach(['deploy/configure-company-os-http.sh','deploy/configure-dev-tls.sh'] as $path){
    $proxy=$read($path);
    foreach(['location ^~ /api/spatial/','location ^~ /uploads/spatial/','location ^~ /spatial/','SYMFONY_UPSTREAM'] as $needle){
        $assert(str_contains($proxy,$needle),'Spatial ingress cutover missing in '.$path.': '.$needle);
    }
}

$pdoBridge=$read('app/Infrastructure/Platform/Persistence/Pdo/PdoConnection.php');
$assert(!str_contains($pdoBridge,'Phalcon\\'),'Canonical PDO bridge must not depend on Phalcon types.');
$assert(str_contains($pdoBridge,'instanceof PDO'),'Canonical PDO bridge does not accept Symfony PDO.');

echo "Spatial Symfony cutover architecture boundary OK\n";
