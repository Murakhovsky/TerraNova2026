<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = (string) file_get_contents($root . '/app/config/config.php');
$services = (string) file_get_contents($root . '/app/config/services.php');
$deploy = (string) file_get_contents($root . '/deploy/symfony-dev.sh');

foreach ([
    "'canonicalDatabase' => [",
    'cos-symfony-canonical-mysql',
    'cos_compat_app',
    'hash(\'sha256\', \'cos-canonical-compat|\' . $legacyDatabasePassword)',
] as $needle) {
    if (!str_contains($config, $needle)) {
        throw new RuntimeException('Canonical compatibility DB configuration is missing: ' . $needle);
    }
}

if (!str_contains($services, "setShared('canonicalDatabaseService'")) {
    throw new RuntimeException('Compatibility composition root must expose canonicalDatabaseService.');
}
if (!str_contains($services, 'getConfig()->canonicalDatabase')) {
    throw new RuntimeException('canonicalDatabaseService must use the dedicated canonical DB configuration.');
}

$bootstrapFiles = glob($root . '/app/Bootstrap/*.php') ?: [];
foreach ($bootstrapFiles as $file) {
    if (str_contains((string) file_get_contents($file), 'canonicalDatabaseService')) {
        throw new RuntimeException('No business service may use canonicalDatabaseService in bridge-only foundation: ' . basename($file));
    }
}

foreach ([
    'CANONICAL_COMPAT_HOST="cos-symfony-canonical-mysql"',
    'CANONICAL_COMPAT_USER="cos_compat_app"',
    'network connect --alias "$CANONICAL_COMPAT_HOST"',
    'cos-canonical-compat|$LEGACY_APP_DB_PASSWORD',
    'GRANT SELECT, INSERT, UPDATE, DELETE ON',
    'Legacy compatibility runtime can reach canonical COS MySQL through the cutover bridge.',
] as $needle) {
    if (!str_contains($deploy, $needle)) {
        throw new RuntimeException('Canonical DB bridge deploy contract is missing: ' . $needle);
    }
}

foreach (['GRANT ALL', 'GRANT CREATE', 'GRANT DROP', 'GRANT ALTER'] as $forbidden) {
    if (str_contains($deploy, $forbidden)) {
        throw new RuntimeException('Canonical compatibility account gained schema/admin privileges: ' . $forbidden);
    }
}

echo "Canonical compatibility DB bridge contract passed.\n";
