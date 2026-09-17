<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/app/Kernel/Tool';
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'App\\Infrastructure\\', 'Infrastructure\\'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $contents = file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) {
        if (str_contains($contents, $needle)) throw new RuntimeException($file->getPathname() . ' depends on ' . $needle);
    }
}
echo "Kernel Tool Runtime boundary passed.\n";
