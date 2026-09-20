<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = (string) file_get_contents($root . '/app/config/config.php');
$services = (string) file_get_contents($root . '/app/config/services.php');

foreach ([
    "'canonicalDatabase' => [",
    'cos-symfony-canonical-mysql',
    'cos_compat_app',
    "hash('sha256', 'cos-canonical-compat|' . $legacyDatabasePassword)",
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

echo "Canonical compatibility DB composition contract passed.\n";
