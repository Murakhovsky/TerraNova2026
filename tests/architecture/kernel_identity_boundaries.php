<?php
declare(strict_types=1);

$identityRoot = dirname(__DIR__, 2) . '/app/Kernel/Identity';
if (!is_dir($identityRoot)) {
    fwrite(STDERR, "Kernel Identity directory is missing.\n");
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

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($identityRoot));
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
                "Kernel Identity boundary violation: %s contains forbidden dependency %s.\n",
                $file->getPathname(),
                $needle,
            ));
            exit(1);
        }
    }
}

echo "Kernel Identity boundary is framework and infrastructure independent.\n";
