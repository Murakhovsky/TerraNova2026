<?php
declare(strict_types=1);


use Phalcon\Mvc\Router;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Mvc\Url as UrlResolver;
use Phalcon\Translate\Adapter\NativeArray;
use Modules\Users\Listeners\UserEventsListener;
use Common\Services\EventService;

/**
 * Registering a router
 */
$di->setShared('router', function () {
    $router = new Router();
    $router->setDefaultModule('TgAdmin');
    return $router;
});


//$di->setShared('walletService', function () {
//    return new WalletService();
//});

/**
 * The URL component is used to generate all kinds of URLs in the application
 */
$di->setShared('url', function () {
    $config = $this->getConfig();

    $url = new UrlResolver();
    $url->setBaseUri($config->application->baseUri);

    return $url;
});


