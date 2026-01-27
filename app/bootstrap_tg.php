<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

try {
    require BASE_PATH . '/vendor/autoload.php';

    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();

    /**
     * The FactoryDefault Dependency Injector automatically registers the services that
     * provide a full stack framework. These default services can be overidden with custom ones.
     */
    $di = new FactoryDefault();

    /**
     * Include general services
     */
    require APP_PATH . '/config/services.php';

    /**
     * Include web environment specific services
     */
    require APP_PATH . '/config/services_tg.php';

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
        'TgAdmin' =>[
            'className' => 'Modules\TgAdmin\Module',
            'path'      => APP_PATH . '/modules/TgAdmin/Module.php',
            'default'   => true
        ],
        'users' => [
            'className' => 'Modules\Users\Module',
            'path'      => APP_PATH . '/modules/Users/Module.php',
        ],
        'economy' => [
            'className' => 'Modules\Economy\Module',
            'path'      => APP_PATH . '/modules/economy/Module.php',
        ],
        'games' => [
            'className' => 'Modules\Games\Module',
            'path'      => APP_PATH . '/modules/Games/Module.php',
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
