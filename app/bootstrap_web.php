<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/config/environment.php';

require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

try {
    /**
     * The FactoryDefault Dependency Injector automatically registers the services that
     * provide a full stack framework. These default services can be overidden with custom ones.
     */
    $di = new FactoryDefault();

    /**
     * Include web environment specific services
     */
    require APP_PATH . '/config/services_web.php';


    /**
     * Include general services
     */
    require APP_PATH . '/config/services.php';

    /**
     * Get config service for use in inline setup below
     */
    $config = $di->getConfig();

    /**
     * Include Autoloader
     */
    include APP_PATH . '/config/loader.php';

    /**
     * Handle the request
     */
    $application = new Application($di);

    /**
     * Register application modules
     */
    $application->registerModules([
        'frontend' => [
            'className' => 'Interfaces\Web\Module',
            'path'      => APP_PATH . '/Interfaces/Web/Module.php',
            'default'   => true
        ],
        'spatial' => [
            'className' => 'Bootstrap\SpatialModule',
            'path'      => APP_PATH . '/Bootstrap/SpatialModule.php',
        ],
    ]);

    /**
     * Include routes
     */
    require APP_PATH . '/config/routes.php';

    echo $application->handle($_SERVER['REQUEST_URI'])->getContent();
} catch (\Throwable $e) {
    if (isset($di) && $di->has('cosLogger')) {
        $di->getShared('cosLogger')->log('error', 'Unhandled web exception.', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);
    } else {
        error_log('Unhandled web exception: ' . $e->getMessage());
    }
    http_response_code(500);
    echo 'Internal Server Error';
}
