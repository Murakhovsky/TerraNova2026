<?php
declare(strict_types=1);

use Infrastructure\Database\Connection\DatabaseService;
use Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
require APP_PATH . '/config/loader.php';

$config = require APP_PATH . '/config/config.php';
$database = new DatabaseService($config->database);
$organizationId = (string) ($_ENV['COS_ORGANIZATION_ID'] ?? 'default');
$readModel = new MysqlClientCaseReadModel($database->connection(), $organizationId);

$filters = $readModel->filters([
    'stage' => 'invalid', 'status' => 'active', 'priority' => 'urgent', 'assigned_user_id' => '-5', 'sort' => 'budget',
]);
if ($filters['stage'] !== '' || $filters['status'] !== 'active' || $filters['priority'] !== 'urgent'
    || $filters['assigned_user_id'] !== 0 || $filters['sort'] !== 'budget') {
    throw new RuntimeException('Client-case filter normalization failed.');
}

$cases = $readModel->cases($filters);
foreach ($cases as $case) {
    if (($case['organization_id'] ?? null) !== $organizationId) {
        throw new RuntimeException('Client-case read model leaked a foreign organization row.');
    }
}
if (!array_key_exists('all', $readModel->stats())) throw new RuntimeException('Client-case stats are incomplete.');
if ($readModel->case(PHP_INT_MAX) !== null) throw new RuntimeException('Unknown client case must not resolve.');
if (!is_array($readModel->openCaseOptions()) || !is_array($readModel->managerOptions())) {
    throw new RuntimeException('Client-case option queries failed.');
}
$inboundFilters = $readModel->inboundFilters(['status' => 'new', 'has_case' => 'no']);
if ($inboundFilters['status'] !== 'new' || $inboundFilters['has_case'] !== 'no') {
    throw new RuntimeException('Inbound filter normalization failed.');
}
foreach ($readModel->inboundInbox($inboundFilters) as $lead) {
    if (($lead['organization_id'] ?? null) !== $organizationId) {
        throw new RuntimeException('Inbound inbox leaked a foreign organization row.');
    }
}
if (!is_array($readModel->inboundInboxStats())
    || $readModel->inboundRequests(PHP_INT_MAX) !== []
    || $readModel->activities(PHP_INT_MAX) !== []
    || $readModel->propertyMatches(PHP_INT_MAX) !== []
    || $readModel->requestMatches(PHP_INT_MAX) !== []
    || $readModel->leadActivities(PHP_INT_MAX) !== []) {
    throw new RuntimeException('Client-case detail queries failed.');
}

echo "Client-case MySQL read model passed.\n";
