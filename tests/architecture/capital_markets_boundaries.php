<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$domainRoot = $root . '/app/Domains/CapitalMarkets';
$pureDomainRoot = $domainRoot . '/Domain';

if (!is_dir($domainRoot) || !is_dir($domainRoot . '/Application') || !is_dir($pureDomainRoot)) {
    throw new RuntimeException('Capital Markets bounded context layout is incomplete.');
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pureDomainRoot));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

if ($files === []) {
    throw new RuntimeException('Capital Markets pure Domain contains no PHP model.');
}

$forbidden = [
    'Symfony\\',
    'Phalcon\\',
    'Infrastructure\\',
    'Platform\\',
    'PDO',
    'curl_',
    'Guzzle',
    'Binance',
    'Kraken',
    'Bybit',
];

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    foreach ($forbidden as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException(sprintf(
                'Capital Markets pure Domain depends on forbidden runtime/integration symbol %s in %s.',
                $needle,
                substr($file, strlen($root) + 1),
            ));
        }
    }
}

if (is_file($pureDomainRoot . '/Value/Money.php')) {
    throw new RuntimeException('Capital Markets must reuse Kernel\\Shared\\Domain\\Money instead of duplicating Money.');
}

$manifest = require $domainRoot . '/module.php';
if (($manifest['enabled_by_default'] ?? true) !== false) {
    throw new RuntimeException('Capital Markets Foundation must remain disabled by default.');
}
if (($manifest['contributions']['runtime_module_service'] ?? 'unexpected') !== null) {
    throw new RuntimeException('Capital Markets Foundation must not register runtime execution.');
}
if (($manifest['contributions']['migration_files'] ?? ['unexpected']) !== []) {
    throw new RuntimeException('Capital Markets Foundation must not own persistence yet.');
}

$readme = (string) file_get_contents($domainRoot . '/README.md');
foreach ([
    'does not fetch market data',
    'does **not** create another Money class',
    'disabled by default',
    'no exchange SDK or HTTP client',
] as $needle) {
    if (!str_contains($readme, $needle)) {
        throw new RuntimeException('Capital Markets foundation boundary is undocumented: ' . $needle);
    }
}

echo sprintf("Capital Markets boundaries passed: %d pure domain files.\n", count($files));
