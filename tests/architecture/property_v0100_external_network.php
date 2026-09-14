<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('app/migrations/20260914_000057_property_v0100_external_network.sql');
foreach ([
    'tn_property_network_connectors',
    'tn_property_network_sync_runs',
    'tn_property_network_records',
    'configuration_reference',
    'payload_hash',
    'source_id',
    'submission_id',
    'asset_id',
    "VALUES ('20260914_000057_property_v0100_external_network')",
] as $needle) {
    $assert(str_contains($migration, $needle), 'Property V0.10 network schema missing: ' . $needle);
}
foreach (['password VARCHAR','secret VARCHAR','access_token','refresh_token','DELETE FROM tn_property_assets','UPDATE tn_property_assets'] as $forbidden) {
    $assert(!str_contains($migration, $forbidden), 'Property Network must not persist credentials or remotely mutate canonical Asset storage: ' . $forbidden);
}
$assert(str_contains($migration, 'REFERENCES tn_property_sources'), 'Network connector/record must reuse V0.4 PropertySource provenance.');
$assert(str_contains($migration, "ENUM('owner','realtor','developer','partner','other','network')"), 'Canonical intake must accept network submissions.');
$assert(str_contains($migration, 'MODIFY owner_name VARCHAR(160) NULL'), 'External feeds must not invent an owner merely to satisfy a legacy form constraint.');

$connector = $read('app/Domains/Property/Application/Contract/PropertyNetworkConnectorInterface.php');
foreach (['function code','function pull','function push','PropertyNetworkBatch','PropertyNetworkDeliveryResult'] as $needle) {
    $assert(str_contains($connector, $needle), 'Connector contract missing: ' . $needle);
}

$sync = $read('app/Domains/Property/Application/Service/PropertyNetworkSyncService.php');
foreach (['TOMBSTONE','alreadyProcessed','advanceCursor','PARTIAL','BIDIRECTIONAL','PropertyNetworkIntakePort','PropertyNetworkExportPort'] as $needle) {
    $assert(str_contains($sync, $needle), 'Network synchronization lifecycle missing: ' . $needle);
}
foreach (['tn_property_assets','tn_properties','DELETE FROM'] as $forbidden) {
    $assert(!str_contains($sync, $forbidden), 'Application Network service must not bypass Property contracts: ' . $forbidden);
}

$intake = $read('app/Domains/Property/Infrastructure/Network/PropertyNetworkIntakeAdapter.php');
foreach (['PropertySubmissionRepositoryInterface',"'source_type' => 'network'","'submission_ref'"] as $needle) {
    $assert(str_contains($intake, $needle), 'Network intake must route through PropertySubmission: ' . $needle);
}
foreach (['tn_property_assets','tn_properties','INSERT INTO'] as $forbidden) {
    $assert(!str_contains($intake, $forbidden), 'Network intake adapter must not write canonical storage directly: ' . $forbidden);
}

$export = $read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyNetworkExportReadModel.php');
foreach (['tn_property_assets','tn_property_inventory_items','tn_property_listings','PropertyNetworkRecord'] as $needle) {
    $assert(str_contains($export, $needle), 'Network export projection missing canonical source: ' . $needle);
}
$assert(!str_contains($export, 'tn_properties'), 'V0.10 export must not fall back to legacy tn_properties.');

$module = require $root . '/app/Domains/Property/module.php';
$assert(($module['version'] ?? null) === '0.10.0', 'Property module must be V0.10.0.');
$assert(($module['schema_version'] ?? null) === '0.10.0', 'Property schema must be V0.10.0.');
$assert(in_array('property.network', $module['contributions']['capabilities'] ?? [], true), 'Property V0.10 network capability missing.');
$assert(in_array('app/migrations/20260914_000057_property_v0100_external_network.sql', $module['contributions']['migration_files'] ?? [], true), 'Property V0.10 migration missing from manifest.');

$composition = $read('app/config/services_kernel.php');
$assert(str_contains($composition, 'PropertyNetworkServices.php'), 'Property Network composition root is not loaded.');

echo "Property V0.10 external network architecture: OK\n";
