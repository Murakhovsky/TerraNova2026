<?php
declare(strict_types=1);

$base = dirname(__DIR__, 2) . '/app/Domains';
$domains = ['Service', 'Finance', 'Procurement'];
$forbidden = ['Symfony\\', 'Phalcon\\', 'PDO', 'Infrastructure\\', 'Platform\\', 'Interfaces\\'];

foreach ($domains as $domain) {
    $root = $base . '/' . $domain . '/Domain';
    if (!is_dir($root)) {
        fwrite(STDERR, sprintf("%s Domain skeleton is missing.\n", $domain));
        exit(1);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        if (!is_string($content)) exit(1);
        foreach ($forbidden as $needle) {
            if (str_contains($content, $needle)) {
                fwrite(STDERR, sprintf("%s boundary violation: %s contains %s.\n", $domain, $file->getPathname(), $needle));
                exit(1);
            }
        }
    }
}

echo "Service, Finance and Procurement skeletons are framework and infrastructure independent.\n";
