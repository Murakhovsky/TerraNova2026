<?php
declare(strict_types=1);

use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Mvc\View;
use Infrastructure\Persistence\MySql\Database\Connection\DatabaseService;
use Infrastructure\Media\ImageOptimizerService;
use Infrastructure\Media\MediaStorageService;
use Infrastructure\Integration\Telegram\TelegramAutomationService;
use Infrastructure\Framework\PhalconEventService;
use Infrastructure\Identity\SessionAuthService;
use Interfaces\Web\Tenant\SessionOrganizationContext;
use Interfaces\Web\Security\CsrfTokenManager;
use Interfaces\Web\Assets\ViteAssetManifest;
use Infrastructure\Security\TelegramAccessPolicy;

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
        'port'     => $config->database->port,
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

$di->setShared('telegramAutomationService', function () {
    return new TelegramAutomationService($this->getShared('databaseService'));
});

$di->setShared('mediaStorageService', function () {
    return new MediaStorageService($this->getShared('databaseService'), $this->getShared('imageOptimizerService'));
});

$di->setShared('imageOptimizerService', function () {
    return new ImageOptimizerService();
});

$di->setShared('authService', function () {
    return new SessionAuthService($this->getShared('databaseService'), $this->getShared('session'));
});

$di->setShared('organizationContext', function () {
    return new SessionOrganizationContext(
        $this->getShared('authService'),
        (string) $this->getConfig()->cos->organizationId,
    );
});

$di->setShared('csrfTokenManager', fn () => new CsrfTokenManager($this->getShared('session')));

$di->setShared('viteAssetManifest', fn () => new ViteAssetManifest(
    BASE_PATH . '/public/build/.vite/manifest.json',
));

$di->setShared('telegramAccessPolicy', fn () => new TelegramAccessPolicy(
    $this->getShared('databaseService'),
));

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
    $eventService = new PhalconEventService();
    return $eventService;
});

if (!function_exists('di')) {
    function di(?string $service = null)
    {
        $di = \Phalcon\Di\Di::getDefault();
        return $service ? $di->getShared($service) : $di;
    }
}

require_once APP_PATH . '/Bootstrap/InboundCaseResolverAdapter.php';
require_once APP_PATH . '/Bootstrap/WebApplicationServices.php';
require APP_PATH . '/config/services_kernel.php';
