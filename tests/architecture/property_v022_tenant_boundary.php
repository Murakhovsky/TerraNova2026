<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

if (($manifest['version'] ?? null) !== '0.2.2') {
    $fail('Property V0.2.2 manifest version is required.');
}
if (($manifest['schema_version'] ?? null) !== '0.2.2') {
    $fail('Property V0.2.2 schema version is required.');
}

$migrationPath = 'app/migrations/20260914_000050_property_v022_tenant_boundary.sql';
$migrations = $manifest['contributions']['migration_files'] ?? [];
if (!in_array($migrationPath, $migrations, true)) {
    $fail('Property V0.2.2 tenant migration is not owned by the module manifest.');
}

$migration = (string) file_get_contents($root . '/' . $migrationPath);
foreach ([
    'ALTER TABLE tn_property_groups',
    'ALTER TABLE tn_property_submissions',
    'ALTER TABLE tn_property_images',
    'ALTER TABLE tn_property_features',
    'ALTER TABLE tn_property_activities',
    'ADD COLUMN organization_id VARCHAR(64)',
    'FOREIGN KEY (organization_id, property_id)',
    'REFERENCES tn_properties (organization_id, id)',
    'FOREIGN KEY (organization_id, property_group_id)',
    'REFERENCES tn_property_groups (organization_id, id)',
] as $needle) {
    if (!str_contains($migration, $needle)) {
        $fail('Tenant schema invariant is missing: ' . $needle);
    }
}

$management = (string) file_get_contents($root . '/app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyManagementRepository.php');
foreach ([
    'private string $organizationId',
    'p.organization_id = :organization_id',
    'g.organization_id = :organization_id',
    'INSERT INTO tn_properties (',
    'organization_id, property_id, user_id',
    'AND organization_id = :organization_id',
    'private function ownsProperty',
] as $needle) {
    if (!str_contains($management, $needle)) {
        $fail('Property management persistence lost tenant scope: ' . $needle);
    }
}
if (str_contains($management, '$where = [\'1 = 1\'];')) {
    $fail('Property admin query can no longer start from an unscoped tenant predicate.');
}

$submission = (string) file_get_contents($root . '/app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertySubmissionRepository.php');
foreach ([
    'private string $organizationId',
    'INSERT INTO tn_property_submissions',
    'organization_id,',
    's.organization_id = :organization_id',
    'AND organization_id = :organization_id',
] as $needle) {
    if (!str_contains($submission, $needle)) {
        $fail('Property submission persistence lost tenant scope: ' . $needle);
    }
}

$moderation = (string) file_get_contents($root . '/app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyModerationRepository.php');
foreach ([
    'private string $organizationId',
    's.organization_id = :organization_id',
    'INSERT INTO tn_properties (',
    'organization_id, property_id, image_url',
    'organization_id, property_id, feature_key',
] as $needle) {
    if (!str_contains($moderation, $needle)) {
        $fail('Property moderation persistence lost tenant scope: ' . $needle);
    }
}

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach ([
    "new MysqlPropertySubmissionRepository(\n            \$di->getShared('databaseService'), \$di->getShared('organizationContext')->id(),",
    'new MysqlPropertyModerationRepository(',
    'new MysqlPropertyManagementRepository(',
] as $needle) {
    if (!str_contains($webServices, $needle)) {
        $fail('Property composition root lost tenant-scoped persistence wiring.');
    }
}
if (substr_count($webServices, "getShared('organizationContext')->id()") < 3) {
    $fail('All mutable Property persistence adapters must receive organization scope.');
}

echo "Property V0.2.2 tenant boundary architecture: OK\n";
