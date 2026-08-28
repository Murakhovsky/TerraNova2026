<?php
declare(strict_types=1);


use Phalcon\Mvc\Router;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Mvc\Url as UrlResolver;
use Phalcon\Translate\Adapter\NativeArray;
use Interfaces\Telegram\Listener\IdentityUserEventsListener;
use Infrastructure\Identity\TelegramNotificationService;
use Infrastructure\Identity\TelegramUserService;

/**
 * Registering a router
 */
$di->setShared('router', function () {
    $router = new Router();
    $router->setDefaultModule('TgAdmin');
    return $router;
});


$di->setShared('userService', fn () => new TelegramUserService());
$di->setShared('notificationService', fn () => new TelegramNotificationService());

/** @var \Infrastructure\Framework\PhalconEventService $legacyEvents */
$legacyEvents = $di->getShared('eventService');
foreach ([
    'user:newProfileSaved', 'user:registered', 'user:profileCompleted', 'user:login',
    'user:loginDaily', 'user:logout', 'user:levelUp', 'user:xpAdded',
    'user:statusChanged', 'user:referralJoined', 'user:referralActivated',
] as $eventName) {
    $legacyEvents->attach($eventName, new IdentityUserEventsListener());
}

/**
 * The URL component is used to generate all kinds of URLs in the application
 */
$di->setShared('url', function () {
    $config = $this->getConfig();

    $url = new UrlResolver();
    $url->setBaseUri($config->application->baseUri);

    return $url;
});


