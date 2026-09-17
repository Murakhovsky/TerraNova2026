<?php
declare(strict_types=1);

$tenantRoot = dirname(__DIR__, 2) . '/app/Kernel/Tenant';
if (!is_dir($tenantRoot)) {
    fwrite(STDERR, "Kernel Tenant directory is missing.\n");
    exit(1);
}

$forbidden = [
    'Symfony\\',
    'Phalcon\\',
    'PDO',
    'Infrastructure\\',
    'App\\',
    'Domains\\',
    'Interfaces\\',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tenantRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) {
        fwrite(STDERR, sprintf("Could not read %s.\n", $file->getPathname()));
        exit(1);
    }

    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            fwrite(STDERR, sprintf(
                "Kernel Tenant boundary violation: %s contains forbidden dependency %s.\n",
                $file->getPathname(),
                $needle,
            ));
            exit(1);
        }
    }
}

echo "Kernel Tenant boundary is framework and infrastructure independent.\n";
