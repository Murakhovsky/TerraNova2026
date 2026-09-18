<?php
declare(strict_types=1);

use Domains\Sales\Application\Service\SalesAutomationRunner;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';

$options = getopt('', ['organization::', 'no-activity-hours::', 'limit::']);
$organization = trim((string) ($options['organization'] ?? ''));
$hours = max(1, (int) ($options['no-activity-hours'] ?? 48));
$limit = max(1, min(1000, (int) ($options['limit'] ?? 200)));

/** @var SalesAutomationRunner $runner */
$runner = $di->getShared('salesAutomationRunner');

echo json_encode(
    $runner->run(
        $organization !== '' ? $organization : null,
        new DateTimeImmutable(),
        $hours,
        $limit,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
) . PHP_EOL;
