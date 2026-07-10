<?php
declare(strict_types=1);

use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Mvc\View;
use Common\Services\AuthService;
use Common\Services\DatabaseService;
use Common\Services\EventService;
use Common\Services\MediaStorageService;

/**
 * Shared configuration service
 */
$di->setShared('config', function () {
    return include APP_PATH . "/config/config.php";
});

/**
 * Database connection is created based in the parameters defined in the configuration file
 */
$di->setShared('db', function () {
    $config = $this->getConfig();

    $class = 'Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
    $params = [
        'host'     => $config->database->host,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset
    ];

    if ($config->database->adapter == 'Postgresql') {
        unset($params['charset']);
    }

    return new $class($params);
});

$di->setShared('databaseService', function () {
    return new DatabaseService($this->getConfig()->database);
});

$di->setShared('mediaStorageService', function () {
    return new MediaStorageService($this->getShared('databaseService'));
});

$di->setShared('authService', function () {
    return new AuthService($this->getShared('databaseService'), $this->getShared('session'));
});

/**
 * If the configuration specify the use of metadata adapter use it or use memory otherwise
 */
$di->setShared('modelsMetadata', function () {
    return new MetaDataAdapter();
});

//  **Реєструємо view**, щоб Phalcon не падав
$di->setShared('view', function() {
    $view = new View();
    $view->disable();      // вимикаємо будь-яке рендерення
    return $view;
});

$di->setShared('eventService', function () use ($di) {
    $eventService = new EventService();
//    $eventService->attach('user:profileCompleted', new \Modules\Users\Listeners\UserEventsListener());
    return $eventService;
});

if (!function_exists('di')) {
    function di(?string $service = null)
    {
        $di = \Phalcon\Di\Di::getDefault();
        return $service ? $di->getShared($service) : $di;
    }
}
