<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/app/Domains/Sales/Domain';
if (!is_dir($root)) {
    fwrite(STDERR, "Sales Domain directory is missing.\n");
    exit(1);
}

$forbidden = [
    'Symfony\\',
    'Phalcon\\',
    'PDO',
    'Infrastructure\\',
    'Platform\\',
    'Domains\\Sales\\Application\\',
    'Domains\\Sales\\Infrastructure\\',
    'Domains\\Sales\\Model\\',
    'Interfaces\\',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) exit(1);
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf("Sales Domain boundary violation: %s contains %s.\n", $file->getPathname(), $needle));
            exit(1);
        }
    }
}

echo "Sales Domain boundary is framework, infrastructure and legacy-model independent.\n";
