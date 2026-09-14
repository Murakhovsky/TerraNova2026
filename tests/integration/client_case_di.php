<?php
declare(strict_types=1);

use Domains\Sales\Application\UseCase\CreateClientCase;
use Domains\Sales\Application\UseCase\UpdateInboundClientCaseRequest;
use Interfaces\Web\Module;
use Interfaces\Web\Service\ClientCaseService;
use Phalcon\Di\FactoryDefault;

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');
require APP_PATH . '/config/environment.php';
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services_web.php';
require APP_PATH . '/config/services.php';
$module = new Module();
$module->registerAutoloaders($di);
$module->registerServices($di);

$service = $di->getShared('frontendClientCaseService');
if (!$service instanceof ClientCaseService) throw new RuntimeException('ClientCase compatibility facade is not wired.');
if (!$di->getShared('salesCreateClientCase') instanceof CreateClientCase) throw new RuntimeException('CreateClientCase is not wired.');
if (!$di->getShared('salesUpdateInboundClientCaseRequest') instanceof UpdateInboundClientCaseRequest) {
    throw new RuntimeException('UpdateInboundClientCaseRequest is not wired.');
}
$resolver = $di->getShared('salesInboundCaseResolver');
if ($resolver->resolvePropertyId(-1) !== null) throw new RuntimeException('Inbound property resolver accepted an invalid property.');

echo "ClientCase frontend DI wiring passed.\n";
