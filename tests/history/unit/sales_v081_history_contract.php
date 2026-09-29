<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = file_get_contents($root . '/app/migrations/20260913_000044_sales_v081_historical_stage_history.sql');
$module = require $root . '/app/Domains/Sales/module.php';

foreach ([
    'sales_deal_stage_history',
    "ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED')",
    'entered_at DATETIME(6) NULL',
    'source_event_id VARCHAR(40) NULL',
    "'ESTIMATED'",
] as $needle) {
    if (!str_contains($migration, $needle)) {
        throw new RuntimeException('Missing V0.8.1 migration contract: ' . $needle);
    }
}
if (str_contains($migration, 'FOREIGN KEY (to_stage_id)') || str_contains($migration, 'FOREIGN KEY (from_stage_id)')) {
    throw new RuntimeException('Historical stage snapshots must not depend on mutable stage foreign keys.');
}
if (($module['version'] ?? null) !== '0.8.1' || ($module['schema_version'] ?? null) !== '0.8.1') {
    throw new RuntimeException('Sales module manifest must advertise V0.8.1.');
}
if (($module['kernel_constraint'] ?? null) !== '>=0.11.0 <0.12.0') {
    throw new RuntimeException('Sales V0.8.1 must declare compatibility with Kernel 0.11.x.');
}
$consumerServices = $module['contributions']['extension_services']['event.consumers'] ?? [];
if (!in_array('salesHistoricalEventConsumer', $consumerServices, true)) {
    throw new RuntimeException('Sales historical event consumer is not registered by module manifest.');
}
$migrations = $module['contributions']['migration_files'] ?? [];
if (!in_array('app/migrations/20260913_000044_sales_v081_historical_stage_history.sql', $migrations, true)) {
    throw new RuntimeException('Sales V0.8.1 migration is not owned by Sales module.');
}

echo "Sales V0.8.1 history contract: OK\n";
