<?php
declare(strict_types=1);

$domainRoot = dirname(__DIR__, 2) . '/app/Domains/Sales/Domain';
$canonicalContexts = ['Lead', 'Contact', 'Company', 'Opportunity', 'Pipeline', 'Activity'];
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

foreach ($canonicalContexts as $context) {
    $root = $domainRoot . '/' . $context;
    if (!is_dir($root)) {
        fwrite(STDERR, sprintf("Canonical Sales context %s is missing.\n", $context));
        exit(1);
    }

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
}

$legacyPolicy = $domainRoot . '/Policy/StageTransitionPolicy.php';
if (!is_file($legacyPolicy)) {
    fwrite(STDERR, "Expected legacy StageTransitionPolicy compatibility island is missing.\n");
    exit(1);
}

echo "Canonical Sales contexts are framework, infrastructure and legacy-model independent; legacy Policy remains an explicit compatibility island.\n";
