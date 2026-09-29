<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('app/migrations/20260914_000056_property_v090_intelligence.sql');
foreach (['tn_property_intelligence_snapshots','tn_property_intelligence_comparables','evidence_hash','facts_json','market_signals_json','output_json','superseded_at','confidence'] as $needle) {
    $assert(str_contains($migration, $needle), 'Property V0.9 intelligence schema missing: ' . $needle);
}
foreach (['ALTER TABLE tn_property_assets','UPDATE tn_property_assets','ALTER TABLE tn_property_inventory_items'] as $forbidden) {
    $assert(!str_contains($migration, $forbidden), 'Derived intelligence must not mutate canonical fact storage: ' . $forbidden);
}

$provider = $read('app/Domains/Property/Infrastructure/AI/StructuredLlmPropertyIntelligenceProvider.php');
foreach (['StructuredLlmClientInterface','property.intelligence.generate','Estimates are inferences, not truth','Never rewrite, repair or invent source facts','responseSchema'] as $needle) {
    $assert(str_contains($provider, $needle), 'Property V0.9 governed intelligence provider missing: ' . $needle);
}

$repository = $read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyIntelligenceRepository.php');
$assert(str_contains($repository, 'UPDATE tn_property_intelligence_snapshots'), 'New intelligence must supersede the prior current snapshot.');
$assert(!str_contains($repository, 'UPDATE tn_property_assets'), 'Intelligence repository must never rewrite Property facts.');
$assert(!str_contains($repository, 'UPDATE tn_property_inventory_items'), 'Intelligence repository must never rewrite Inventory facts.');

$coordinator = $read('app/Infrastructure/Platform/Analytics/PropertyIntelligenceCoordinator.php');
foreach (['PropertyReferencePort','PropertyMarketAnalyticsService','PropertyComparableSelector','PropertyIntelligenceContext'] as $needle) {
    $assert(str_contains($coordinator, $needle), 'Property V0.9 intelligence orchestration missing: ' . $needle);
}

$module = require $root . '/app/Domains/Property/module.php';
$assert(version_compare((string) ($module['version'] ?? '0.0.0'), '0.9.0', '>='), 'Property module must retain V0.9+ intelligence.');
$assert(version_compare((string) ($module['schema_version'] ?? '0.0.0'), '0.9.0', '>='), 'Property schema must retain V0.9+ intelligence.');
$assert(in_array('property.intelligence', $module['contributions']['capabilities'] ?? [], true), 'Property V0.9 intelligence capability missing.');
$assert(in_array('app/migrations/20260914_000056_property_v090_intelligence.sql', $module['contributions']['migration_files'] ?? [], true), 'Property V0.9 migration missing from manifest.');

echo "Property V0.9 intelligence architecture: OK\n";
