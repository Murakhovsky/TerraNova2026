<?php
declare(strict_types=1);

use Infrastructure\Persistence\MySql\Database\Connection\DatabaseService;
use Infrastructure\Spatial\SpatialProcessingService;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
$database = $di->getShared('databaseService');
assert($database instanceof DatabaseService);
$config = $di->getShared('config')->spatial;
$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, min(50, (int) $options['limit'])) : 10;
$processor = new SpatialProcessingService(
    $database,
    (string) $config->blender_binary,
    (string) $config->gltf_transform_binary
);
echo json_encode($processor->process($limit), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
