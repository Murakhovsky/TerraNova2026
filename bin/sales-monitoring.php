<?php
declare(strict_types=1);

use Domains\Sales\Application\Service\SalesMonitoringService;
use Phalcon\Di\FactoryDefault;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';

$options = getopt('', ['organization::', 'no-activity-hours::', 'limit::']);
$requestedOrganization = trim((string)($options['organization'] ?? ''));
$hours = max(1, (int)($options['no-activity-hours'] ?? 48));
$limit = max(1, min(1000, (int)($options['limit'] ?? 200)));

/** @var SalesMonitoringService $monitoring */
$monitoring = $di->getShared('salesMonitoringService');
$connection = $di->getShared('databaseService')->connection();

if ($requestedOrganization !== '') {
    $organizations = [$requestedOrganization];
} else {
    $statement = $connection->query('SELECT DISTINCT organization_id FROM sales_pipelines WHERE status="ACTIVE" ORDER BY organization_id');
    $organizations = array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))));
}

$now = new DateTimeImmutable();
$result = ['run_at' => $now->format(DATE_ATOM), 'organizations' => []];
foreach ($organizations as $organizationId) {
    $result['organizations'][$organizationId] = [
        'no_activity_detected' => $monitoring->detectNoActivity($organizationId, $now, $hours, $limit),
        'followups_missed' => $monitoring->detectMissedFollowups($organizationId, $now, $limit),
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
