<?php
declare(strict_types=1);

use Domains\Content\Application\Service\ContentService;
use Domains\Property\Application\Service\PropertyManagementService;
use Domains\Property\Application\UseCase\PropertyModerationService;
use Domains\Property\Application\UseCase\PropertySubmissionService;
use Domains\Content\Infrastructure\Persistence\MySql\MysqlContentRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyModerationRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertySubmissionRepository;
use Infrastructure\Platform\Analytics\MysqlPropertyAnalytics;
use Infrastructure\Platform\Analytics\MysqlPropertyFunnelAnalytics;
use Interfaces\Web\Module;
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
(new Module())->registerServices($di);

$expected = [
    'contentRepository' => MysqlContentRepository::class,
    'frontendContentService' => ContentService::class,
    'propertySubmissionRepository' => MysqlPropertySubmissionRepository::class,
    'frontendPropertySubmissionService' => PropertySubmissionService::class,
    'propertyModerationRepository' => MysqlPropertyModerationRepository::class,
    'frontendPropertyModerationService' => PropertyModerationService::class,
    'propertyManagementRepository' => MysqlPropertyManagementRepository::class,
    'frontendPropertyMediaService' => PropertyManagementService::class,
    'propertyAnalytics' => MysqlPropertyAnalytics::class,
    'frontendAnalyticsService' => MysqlPropertyFunnelAnalytics::class,
];

foreach ($expected as $serviceId => $class) {
    $service = $di->getShared($serviceId);
    if (!$service instanceof $class) {
        throw new RuntimeException(sprintf('%s must resolve to %s, got %s.', $serviceId, $class, get_debug_type($service)));
    }
}

echo "Persistence DI wiring passed: Domain services wrap canonical MySQL repositories.\n";
