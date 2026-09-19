<?php
declare(strict_types=1);

use Phalcon\Html\Escaper;
use Phalcon\Flash\Direct as Flash;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Router;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Mvc\Url as UrlResolver;

///**
// * Registering a router
// */
$di->setShared('router', function () {
    $router = new Router(false);
    $router->removeExtraSlashes(true);
//    $router->setDefaultModule('economy');
    $router->setDefaultModule('frontend');
    return $router;
});

/**
 * The URL component is used to generate all kinds of URLs in the application
 */
$di->setShared('url', function () {
    $config = $this->getConfig();

    $url = new UrlResolver();
    $url->setBaseUri($config->application->baseUri);

    return $url;
});

/**
 * Starts the session the first time some component requests the session service.
 *
 * Authentication is intentionally persistent across browser and workstation
 * restarts. The session id therefore uses a durable cookie and its server-side
 * state is stored on the persistent php_sessions Docker volume.
 */
$di->setShared('session', function () {
    $defaultLifetime = 30 * 24 * 60 * 60;
    $configuredLifetime = (int) (getenv('COS_SESSION_LIFETIME_SECONDS') ?: $defaultLifetime);
    $sessionLifetime = max(3600, $configuredLifetime);
    $sessionPath = getenv('COS_SESSION_SAVE_PATH') ?: '/var/www/html/tmp/sessions';

    if (!is_dir($sessionPath) && !@mkdir($sessionPath, 0770, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create session storage directory: ' . $sessionPath);
    }

    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.cookie_lifetime', (string) $sessionLifetime);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    $session = new SessionManager();
    $files = new SessionAdapter([
        'savePath' => $sessionPath,
    ]);
    $session->setAdapter($files);
    $session->start();

    return $session;
});

/**
 * Register the session flash service with the Twitter Bootstrap classes
 */
$di->set('flash', function () {
    $escaper = new Escaper();
    $flash = new Flash($escaper);
    $flash->setImplicitFlush(false);
    $flash->setCssClasses([
        'error'   => 'alert alert-danger',
        'success' => 'alert alert-success',
        'notice'  => 'alert alert-info',
        'warning' => 'alert alert-warning'
    ]);
});



