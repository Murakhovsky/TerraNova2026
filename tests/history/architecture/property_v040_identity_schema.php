<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$migrationPath = $root . '/app/migrations/20260914_000052_property_v040_identity_provenance.sql';
$migration = file_get_contents($migrationPath);
if ($migration === false) {
    throw new RuntimeException('Property V0.4 migration is missing.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.4.0', '>='), 'Property manifest must be at least V0.4.0.');
$assert(version_compare((string) ($manifest['schema_version'] ?? '0.0.0'), '0.4.0', '>='), 'Property schema version must be at least V0.4.0.');
$assert(in_array('app/migrations/20260914_000052_property_v040_identity_provenance.sql', $manifest['contributions']['migration_files'] ?? [], true), 'Property V0.4 migration must be declared by the module.');

foreach ([
    'tn_property_sources',
    'tn_property_external_references',
    'tn_property_provenance',
    'tn_property_party_relations',
    'tn_property_identity_resolutions',
] as $table) {
    $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'Property V0.4 schema missing table: ' . $table);
}

$assert(str_contains($migration, 'UNIQUE KEY uq_tn_property_external_refs_identity (organization_id, source_system, external_id)'), 'One external identity must map to only one tenant-local canonical asset.');
$assert(str_contains($migration, 'field_path VARCHAR(191) NOT NULL'), 'Provenance must identify the concrete canonical field it describes.');
$assert(str_contains($migration, 'observed_value JSON NOT NULL'), 'Provenance must preserve the observed value rather than only source metadata.');
$assert(str_contains($migration, 'verification_status VARCHAR(32) NOT NULL'), 'Provenance must retain verification state.');
$assert(str_contains($migration, 'party_reference VARCHAR(191) NOT NULL'), 'Property relation must reference a Party identity without copying CRM data.');
$assert(str_contains($migration, 'valid_from TIMESTAMP NULL') && str_contains($migration, 'valid_to TIMESTAMP NULL'), 'Property Party relations must be temporal.');
$assert(str_contains($migration, 'FOREIGN KEY (organization_id, submission_id)'), 'Identity resolution must remain tenant-linked to PropertySubmission intake.');
$assert(str_contains($migration, 'candidate_asset_id VARCHAR(80) NULL') && str_contains($migration, 'resolved_asset_id VARCHAR(80) NULL'), 'Identity resolution must preserve candidate and canonical result.');
$assert(!str_contains($migration, 'price_amount'), 'Property V0.4 must not absorb Inventory or Sales price state.');

echo "Property V0.4 identity and provenance schema: OK\n";
