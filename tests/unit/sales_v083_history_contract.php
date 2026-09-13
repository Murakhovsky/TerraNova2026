<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('app/migrations/20260913_000046_sales_v083_operational_performance.sql');
if (str_contains($migration, 'DELIMITER')) throw new RuntimeException('V0.8.3 migration must not depend on mysql-client DELIMITER directives.');
if (!str_contains($migration, 'sales_deal_stage_history_v067_legacy')) throw new RuntimeException('Legacy stage history must be preserved as forensic archive when possible.');
if (!str_contains($migration, "history_quality ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED')")) throw new RuntimeException('Historical quality contract is missing.');
if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS sales_deal_owner_history')) throw new RuntimeException('Owner-at-time history is missing.');
if (!str_contains($migration, "NULL, NULL, 'PARTIAL'")) throw new RuntimeException('Legacy direct owner capture must be explicitly PARTIAL.');
if (!str_contains($migration, "NULL,\n    NULL,\n    NULL,\n    NULL,\n    'ESTIMATED'")) throw new RuntimeException('Current-state backfill must remain explicitly ESTIMATED.');

$eventTypes = $read('app/Domains/Sales/Automation/Event/SalesEventType.php');
if (!str_contains($eventTypes, "DEAL_OWNER_ASSIGNED = 'sales.deal.owner_assigned'")) throw new RuntimeException('Canonical owner assignment event is missing.');

$assign = $read('app/Domains/Sales/Application/UseCase/AssignDealOwner.php');
if (!str_contains($assign, 'SalesEventType::DEAL_OWNER_ASSIGNED')) throw new RuntimeException('Owner assignment use case must publish the canonical event.');

$stage = $read('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlDealRepository.php');
if (str_contains($stage, 'INSERT INTO sales_deal_stage_history')) throw new RuntimeException('Stage mutation repository must not maintain analytics history directly.');

$consumer = $read('app/Domains/Sales/Automation/Event/SalesHistoricalEventConsumer.php');
if (!str_contains($consumer, 'SalesDealOwnerHistoryProjector')) throw new RuntimeException('Durable historical consumer must project owner history.');

$dictionary = $read('app/Domains/Sales/Application/Service/SalesMetricDictionary.php');
foreach (['response_time','followup_compliance','loss_analysis','manager_performance'] as $metric) {
    if (!str_contains($dictionary, "'$metric'")) throw new RuntimeException("Metric dictionary missing $metric.");
}

$module = require $root . '/app/Domains/Sales/module.php';
if (($module['version'] ?? null) !== '0.8.3' || ($module['schema_version'] ?? null) !== '0.8.3') throw new RuntimeException('Sales module must be V0.8.3.');
if (!in_array('app/migrations/20260913_000046_sales_v083_operational_performance.sql', $module['contributions']['migration_files'] ?? [], true)) throw new RuntimeException('V0.8.3 migration must be registered.');

echo "Sales V0.8.3 history contract: OK\n";
