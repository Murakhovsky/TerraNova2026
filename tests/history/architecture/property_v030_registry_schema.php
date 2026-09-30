<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$migrationPath = $root . '/app/migrations/20260914_000051_property_v030_asset_registry.sql';
$migration = file_get_contents($migrationPath);
if ($migration === false) {
    throw new RuntimeException('Property V0.3 migration is missing.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.3.0', '>='), 'Property manifest must be at least V0.3.0.');
$assert(version_compare((string) ($manifest['schema_version'] ?? '0.0.0'), '0.3.0', '>='), 'Property schema version must be at least V0.3.0.');
$assert(in_array('app/migrations/20260914_000051_property_v030_asset_registry.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Property V0.3 migration must be declared by the module.');

foreach ([
    'tn_property_assets',
    'tn_property_asset_relations',
    'tn_property_residential_specs',
    'tn_property_land_specs',
    'tn_property_commercial_specs',
    'tn_property_building_specs',
    'tn_location_nodes',
    'tn_addresses',
    'tn_geo_points',
    'tn_geo_boundaries',
] as $table) {
    $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'Property V0.3 schema missing table: ' . $table);
}

$assert(str_contains($migration, 'UNIQUE KEY uq_tn_property_assets_tenant_asset (organization_id, asset_id)'), 'Canonical PropertyAsset identity must be tenant-local.');
$assert(str_contains($migration, 'FOREIGN KEY (organization_id, source_asset_id)'), 'Asset relation source must preserve tenant boundary.');
$assert(str_contains($migration, 'FOREIGN KEY (organization_id, target_asset_id)'), 'Asset relation target must preserve tenant boundary.');
$assert(str_contains($migration, 'canonical_key VARCHAR(191) NOT NULL'), 'Location registry must expose a normalized canonical key.');

echo "Property V0.3 registry schema: OK\n";
