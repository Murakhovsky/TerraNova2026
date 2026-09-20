<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$migration = file_get_contents($root . '/app/migrations/20260914_000055_property_v070_history_contracts.sql') ?: '';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$assert(version_compare((string)($manifest['version'] ?? '0.0.0'),'0.7.0','>='),'Property manifest must be at least V0.7.0.');
$assert(in_array('app/migrations/20260914_000055_property_v070_history_contracts.sql',$manifest['contributions']['migration_files'] ?? [],true),'Property V0.7 migration must be declared.');
foreach (['tn_property_lifecycle_history','tn_property_relation_history'] as $table) {
    $assert(str_contains($migration,'CREATE TABLE IF NOT EXISTS ' . $table),'Property V0.7 schema missing history projection: ' . $table);
}
foreach (['tn_property_inventory_price_history','tn_property_inventory_status_history'] as $table) {
    $v05 = file_get_contents($root . '/app/migrations/20260914_000053_property_v050_inventory.sql') ?: '';
    $assert(str_contains($v05,'CREATE TABLE IF NOT EXISTS ' . $table),'V0.7 requires Inventory history projection: ' . $table);
}
$v06 = file_get_contents($root . '/app/migrations/20260914_000054_property_v060_listings_publication.sql') ?: '';
$assert(str_contains($v06,'CREATE TABLE IF NOT EXISTS tn_property_listing_publication_history'),'V0.7 requires Listing publication history.');
$assert(str_contains($migration,'event_id VARCHAR(80) NULL'),'History projections must be able to correlate with canonical Kernel events.');

$services = file_get_contents($root . '/symfony/config/services.yaml') ?: '';
foreach ([
    'Domains\\Property\\Infrastructure\\ReadModel\\MySql\\MysqlPropertyReferencePort:',
    'Domains\\Property\\Contract\\PropertyReferencePort:',
    'alias: Domains\\Property\\Infrastructure\\ReadModel\\MySql\\MysqlPropertyReferencePort',
] as $needle) {
    $assert(str_contains($services,$needle),'PropertyReferencePort must be available from canonical Symfony composition: '.$needle);
}
$assert(!is_file($root.'/app/Bootstrap/PropertyServices.php'),'Retired Property bootstrap must remain deleted.');

echo "Property V0.7 history/contracts schema: OK\n";
