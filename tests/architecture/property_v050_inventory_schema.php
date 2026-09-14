<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$migrationPath = $root . '/app/migrations/20260914_000053_property_v050_inventory.sql';
$migration = file_get_contents($migrationPath);
if ($migration === false) throw new RuntimeException('Property V0.5 migration is missing.');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.5.0', '>='), 'Property manifest must be at least V0.5.0.');
$assert(in_array('app/migrations/20260914_000053_property_v050_inventory.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Property V0.5 migration must be declared.');
foreach (['tn_property_inventory_items','tn_property_inventory_reservations','tn_property_inventory_price_history','tn_property_inventory_status_history'] as $table) {
    $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'Property V0.5 schema missing table: ' . $table);
}
$assert(str_contains($migration, 'FOREIGN KEY (organization_id, asset_id)'), 'Inventory must reference canonical PropertyAsset through tenant identity.');
$assert(str_contains($migration, 'transaction_type VARCHAR(24) NOT NULL'), 'Inventory must own deal/transaction type.');
$assert(str_contains($migration, 'price_amount DECIMAL(18,2) NULL'), 'Inventory must own commercial price.');
$assert(str_contains($migration, "WHEN 'reserved' THEN 'reserved'") && str_contains($migration, "WHEN 'sold' THEN 'sold'"), 'Legacy reserved/sold state must migrate into Inventory rather than Property lifecycle.');
$assert(str_contains($migration, 'effective_at TIMESTAMP NOT NULL'), 'Inventory histories must be temporal append-only facts.');

$events = file_get_contents($root . '/app/Domains/Property/Automation/Event/PropertyEventType.php') ?: '';
foreach (['INVENTORY_CREATED','INVENTORY_PRICE_CHANGED','INVENTORY_STATUS_CHANGED','INVENTORY_RESERVED','INVENTORY_RELEASED'] as $event) {
    $assert(str_contains($events, $event), 'Missing Inventory domain event: ' . $event);
}

echo "Property V0.5 inventory schema: OK\n";
