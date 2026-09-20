<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$module = require $root . '/app/Domains/Property/module.php';
$assert(($module['version'] ?? null) === '0.12.0', 'Property manifest must declare V0.12.0.');
$assert(($module['schema_version'] ?? null) === '0.12.0', 'Property schema must declare V0.12.0.');
$assert(in_array('property.runtime.canonical', $module['contributions']['capabilities'] ?? [], true), 'Canonical runtime capability is missing.');
$migrationPath = 'app/migrations/20260915_000059_property_v0120_runtime_cutover.sql';
$assert(in_array($migrationPath, $module['contributions']['migration_files'] ?? [], true), 'V0.12 migration is not declared by Property manifest.');

$migration = $read($migrationPath);
foreach (['floor_number', 'tn_property_compatibility_projection_state', 'v0.12-baseline'] as $needle) {
    $assert(str_contains($migration, $needle), 'Property V0.12 migration missing: ' . $needle);
}

$services = $read('app/Bootstrap/PropertyServices.php');
foreach (['propertyCanonicalRuntimeRepository', 'propertyCompatibilityProjection', 'propertyCanonicalRuntime'] as $service) {
    $assert(str_contains($services, "setShared('" . $service . "'"), 'Canonical Property DI service missing: ' . $service);
}
$assert(str_contains($services, 'MysqlLocationReference'), 'Compatibility projection must receive the explicit Reference location adapter.');

$web = $read('app/Bootstrap/WebApplicationServices.php');
$assert(str_contains($web, 'CanonicalPropertyManagementWriteRepository'), 'Web Property writes must use canonical adapter.');
$assert(str_contains($web, 'CanonicalPropertyManagementWorkflowRepository'), 'Web Property workflow must use canonical adapter.');
$assert(!str_contains($web, 'new LegacyPropertyManagementWriteRepository'), 'Legacy Property write adapter must not be active in Web DI.');
$assert(!str_contains($web, 'new LegacyPropertyManagementWorkflowRepository'), 'Legacy Property workflow adapter must not be active in Web DI.');

$runtime = $read('app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php');
$repository = $read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyCanonicalRuntimeRepository.php');
foreach ([$runtime, $repository] as $canonical) {
    $assert(!str_contains($canonical, 'tn_properties'), 'Canonical runtime must not use legacy tn_properties storage.');
}
foreach (['PropertyDomainEvents::', 'InventoryDomainEvents::', 'ListingDomainEvents::', 'events->publish'] as $needle) {
    $assert(str_contains($runtime, $needle), 'Canonical runtime is missing event path: ' . $needle);
}

$write = $read('app/Domains/Property/Infrastructure/Persistence/MySql/Management/CanonicalPropertyManagementWriteRepository.php');
$workflow = $read('app/Domains/Property/Infrastructure/Persistence/MySql/Management/CanonicalPropertyManagementWorkflowRepository.php');
foreach ([$write, $workflow] as $adapter) {
    $assert(!str_contains($adapter, 'UPDATE tn_properties'), 'Canonical Web adapter must not mutate tn_properties directly.');
    $assert(!str_contains($adapter, 'INSERT INTO tn_properties'), 'Canonical Web adapter must not create tn_properties directly.');
}
$assert(str_contains($write, 'propertyCompatibilityProjection') || str_contains($write, 'compatibility'), 'Web write adapter must use explicit compatibility bridge.');

$projection = $read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyCompatibilityProjection.php');
$assert(str_contains($projection, 'INSERT INTO tn_properties'), 'Compatibility projection must create legacy projection rows.');
$assert(str_contains($projection, 'UPDATE tn_properties'), 'Compatibility projection must refresh legacy projection rows.');
$assert(str_contains($projection, 'tn_property_compatibility_projection_state'), 'Compatibility projection must track sync state.');
$assert(str_contains($projection, 'LocationReferenceInterface'), 'Compatibility projection must cross the Reference boundary through its port.');
$assert(!preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+tn_locations/i', $projection), 'Property compatibility projection must not write Reference-owned tn_locations directly.');

$routes = $read('symfony/config/routes.yaml');
foreach (['/api/v1/properties', '/api/v1/properties/{id}/inventory', '/api/v1/property-inventory/{id}/status', '/api/v1/property-inventory/{id}/reservations'] as $route) {
    $assert(str_contains($routes, $route), 'Canonical Symfony Property API route missing: ' . $route);
}
$controller = $read('symfony/src/Http/Api/V1/Controller/PropertyController.php');
foreach (['CommandBusInterface', 'QueryBusInterface', 'LegacySessionCsrfValidator', 'ActiveModuleResolver'] as $boundary) {
    $assert(str_contains($controller, $boundary), 'Canonical Symfony Property API boundary missing: ' . $boundary);
}
foreach ([
    'app/Interfaces/Web/Routing/PropertyRuntimeRoutes.php',
    'app/Interfaces/Api/Controller/PropertyRuntimeController.php',
    'app/Interfaces/Api/Controller/PropertyCanonicalController.php',
] as $retired) {
    $assert(!is_file($root . '/' . $retired), 'Retired Property runtime transport restored: ' . $retired);
}

$spatial = $read('app/Bootstrap/WebApplicationServices.php');
$assert(str_contains($spatial, 'CanonicalPropertyTourPublisher'), 'Spatial tour publishing must use canonical Property runtime.');
$assert(!str_contains($spatial, 'new MysqlPropertyTourPublisher'), 'Spatial compatibility DI must not write Property tour state directly to tn_properties.');
$assert(!is_file($root . '/app/Bootstrap/SpatialModule.php'), 'Retired Phalcon Spatial module was restored.');

echo "Property V0.12 canonical runtime architecture: OK\n";
