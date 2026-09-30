<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = file_get_contents($root . '/app/migrations/20260913_000045_sales_v082_funnel_metrics.sql');
$module = require $root . '/app/Domains/Sales/module.php';
$ruleContext = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesRuleContextProvider.php');

foreach (['sales_stage_metric_thresholds','stuck_after_seconds','idx_sales_deal_stage_history_duration'] as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Missing V0.8.2 migration contract: ' . $needle);
}
if (($module['version'] ?? null) !== '0.8.2' || ($module['schema_version'] ?? null) !== '0.8.2') {
    throw new RuntimeException('Sales module manifest must advertise V0.8.2.');
}
if (!in_array('app/migrations/20260913_000045_sales_v082_funnel_metrics.sql', $module['contributions']['migration_files'] ?? [], true)) {
    throw new RuntimeException('Sales V0.8.2 migration is not owned by Sales module.');
}
if (str_contains($ruleContext, '604800')) {
    throw new RuntimeException('Runtime stuck classification must not keep the old hardcoded seven-day threshold.');
}
if (!str_contains($ruleContext, 'sales_stage_metric_thresholds')) {
    throw new RuntimeException('Runtime stuck classification must consume configured stage thresholds.');
}

echo "Sales V0.8.2 metrics contract: OK\n";
