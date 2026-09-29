<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$directories = [
    $root . '/app/Kernel',
    $root . '/app/Platform',
    $root . '/app/Domains',
];

$forbidden = ['getenv(', '$_ENV', '$_SERVER'];
foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                throw new RuntimeException(sprintf(
                    'Canonical business code may not read process environment directly: %s contains %s.',
                    $file->getPathname(),
                    $needle,
                ));
            }
        }
    }
}

$store = (string) file_get_contents($root . '/app/Kernel/Configuration/Contract/ConfigurationStoreInterface.php');
if (!str_contains($store, 'interface ConfigurationStoreInterface')) {
    throw new RuntimeException('Kernel Configuration business contract is missing.');
}
if (str_contains($store, 'Symfony\\') || str_contains($store, 'PDO')) {
    throw new RuntimeException('Kernel Configuration must remain framework and persistence independent.');
}

echo "Runtime configuration boundary passed.\n";
