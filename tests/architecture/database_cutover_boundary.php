<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$allowlist = require $root . '/symfony/config/database-cutover-legacy-services.php';

if (!str_contains($services, 'cos.database.pdo:')
    || !str_contains($services, "$connection: '@doctrine.dbal.default_connection'")) {
    throw new RuntimeException('Canonical COS PDO must be backed by Doctrine DATABASE_URL.');
}

foreach ([
    'App\\Infrastructure\\Module\\PdoModuleStateRepository',
    'App\\Infrastructure\\Module\\PdoModuleLifecycleRepository',
] as $service) {
    $offset = strpos($services, "  {$service}:");
    if ($offset === false) {
        throw new RuntimeException("Missing service: {$service}");
    }
    $chunk = substr($services, $offset, 260);
    if (!str_contains($chunk, "$connection: '@cos.database.pdo'")) {
        throw new RuntimeException("{$service} must use canonical COS PDO.");
    }
}

$lines = preg_split('/\\R/', $services) ?: [];
$current = null;
$legacy = [];
foreach ($lines as $line) {
    if (preg_match('/^  ([A-Za-z0-9_\\\\.\\-]+):\\s*$/', $line, $match) === 1) {
        $current = $match[1];
    }
    if (str_contains($line, '@legacy_cos.pdo') && is_string($current)) {
        $legacy[$current] = true;
    }
}

$unknown = array_values(array_diff(array_keys($legacy), $allowlist));
sort($unknown);
if ($unknown !== []) {
    throw new RuntimeException('New legacy DB dependencies are forbidden: ' . implode(', ', $unknown));
}
if (count($legacy) > count($allowlist)) {
    throw new RuntimeException('Legacy DB dependency count increased.');
}
if (isset($legacy['App\\Infrastructure\\Module\\PdoModuleStateRepository'])
    || isset($legacy['App\\Infrastructure\\Module\\PdoModuleLifecycleRepository'])) {
    throw new RuntimeException('Module runtime repositories regressed to legacy DB.');
}

echo sprintf("Database cutover boundary passed: %d legacy dependencies remain.\\n", count($legacy));
