<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

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
            'className' => 'Modules\Frontend\Module',
            'path'      => APP_PATH . '/modules/frontend/Module.php',
            'default'   => true
        ],
        'economy' => [
            'className' => 'Modules\Economy\Module',
            'path'      => APP_PATH . '/modules/economy/Module.php',
            ],
        'games' => [
            'className' => 'Modules\Games\Module',
            'path'      => APP_PATH . '/modules/Games/Module.php',
        ],
        'users' => [
            'className' => 'Modules\Users\Module',
            'path'      => APP_PATH . '/modules/Users/Module.php',
        ],
    ]);

    /**
     * Include routes
     */
    require APP_PATH . '/config/routes.php';

    echo $application->handle($_SERVER['REQUEST_URI'])->getContent();
} catch (\Exception $e) {
    echo $e->getMessage() . '<br>';
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
}
