<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/config/environment.php';
require APP_PATH . '/config/trusted_proxy.php';

require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

try {
    $di = new FactoryDefault();

    require APP_PATH . '/config/services_web.php';
    require APP_PATH . '/config/services.php';

    $config = $di->getConfig();
    $application = new Application($di);

    $application->registerModules([
        'frontend' => [
            'className' => 'Interfaces\\Web\\Module',
            'path'      => APP_PATH . '/Interfaces/Web/Module.php',
            'default'   => true,
        ],
        'spatial' => [
            'className' => 'Bootstrap\\SpatialModule',
            'path'      => APP_PATH . '/Bootstrap/SpatialModule.php',
        ],
    ]);

    require APP_PATH . '/config/routes.php';

    echo $application->handle($_SERVER['REQUEST_URI'])->getContent();
} catch (\Throwable $e) {
    try {
        $requestId = 'TN-' . strtoupper(bin2hex(random_bytes(5)));
    } catch (\Throwable) {
        $requestId = 'TN-' . strtoupper(substr(hash('sha256', microtime(true) . ':' . mt_rand()), 0, 10));
    }

    $context = [
        'request_id' => $requestId,
        'exception' => $e::class,
        'error' => $e->getMessage(),
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    ];

    if (isset($di) && $di->has('cosLogger')) {
        $di->getShared('cosLogger')->log('error', 'Unhandled web exception.', $context);
    } else {
        error_log(sprintf('Unhandled web exception [%s]: %s', $requestId, $e->getMessage()));
    }

    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    $safeRequestId = htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Технічна помилка | Terra Nova</title><style>body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f6f3ed;color:#111714}main{min-height:100vh;display:grid;place-items:center;padding:24px}.card{max-width:640px;background:#fff;border:1px solid #d9ddd8;border-radius:18px;padding:clamp(24px,5vw,48px)}h1{font-size:clamp(2rem,7vw,4rem);line-height:1;margin:.2em 0}p{line-height:1.6;color:#52605a}code{color:#111714}</style></head><body><main><section class="card"><small>Terra Nova · 500</small><h1>Сталася технічна помилка</h1><p>Ми зафіксували проблему. Спробуйте оновити сторінку трохи пізніше.</p><p>Код звернення: <code>' . $safeRequestId . '</code></p></section></main></body></html>';
}
