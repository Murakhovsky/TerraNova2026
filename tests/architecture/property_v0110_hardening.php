<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$module = require $root . '/app/Domains/Property/module.php';
$assert(($module['version'] ?? null) === '0.11.0', 'Property manifest must declare V0.11.0.');
$assert(($module['schema_version'] ?? null) === '0.11.0', 'Property schema version must declare V0.11.0.');
$assert(in_array('app/migrations/20260914_000058_property_v0110_hardening.sql', $module['contributions']['migration_files'] ?? [], true), 'V0.11 migration missing from Property manifest.');
$assert(in_array('property.identity.review', $module['contributions']['capabilities'] ?? [], true), 'Identity review capability missing.');

$migration = $read('app/migrations/20260914_000058_property_v0110_hardening.sql');
foreach (['review_status', 'tn_property_asset_legacy_links', 'tn_property_identity_review_audit', 'v0.11_canonical_seed'] as $needle) {
    $assert(str_contains($migration, $needle), 'V0.11 migration missing: ' . $needle);
}

$composed = $read('app/Domains/Property/Infrastructure/Persistence/MySql/Management/ComposedPropertyManagementRepository.php');
foreach (['PropertyManagementReadRepositoryInterface', 'PropertyGroupManagementRepositoryInterface', 'PropertyManagementWriteRepositoryInterface', 'PropertyManagementWorkflowRepositoryInterface'] as $needle) {
    $assert(str_contains($composed, $needle), 'Management decomposition missing port: ' . $needle);
}

$web = $read('app/Bootstrap/WebApplicationServices.php');
$assert(str_contains($web, 'ComposedPropertyManagementRepository'), 'Web composition must use the decomposed Property management repository.');
$assert(str_contains($web, "getShared('propertyIdentityWorkflow')"), 'Moderation must be wired to the canonical identity workflow.');
$kernel = $read('app/config/services_kernel.php');
$assert(str_contains($kernel, 'PropertyIdentityServices.php'), 'Property identity services must be loaded by the composition root.');

$reference = $read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyReferencePort.php');
$assert(!str_contains($reference, 'tn_properties'), 'Canonical PropertyReferencePort must not fall back to tn_properties.');
$assert(str_contains($reference, 'tn_property_asset_legacy_links'), 'PropertyReferencePort must resolve V0.11 legacy aliases canonically.');

$reso = $read('app/Domains/Property/Infrastructure/Network/Reso/ResoPropertyNetworkConnector.php');
$assert(str_contains($reso, 'PropertyNetworkConnectorInterface'), 'RESO adapter must implement the V0.10 connector contract.');
$assert(str_contains($reso, 'configuration_reference'), 'RESO adapter must use configuration_reference instead of owning secrets.');
$assert(!str_contains($reso, 'tn_property_assets') && !str_contains($reso, 'tn_properties'), 'RESO adapter must not write Property persistence directly.');

echo "Property V0.11 hardening architecture: OK\n";
