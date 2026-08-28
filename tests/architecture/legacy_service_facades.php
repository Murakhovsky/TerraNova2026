<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach (['app/modules'] as $legacyDirectory) {
    if (is_dir($root . '/' . $legacyDirectory)) {
        throw new RuntimeException('Removed legacy directory was restored: ' . $legacyDirectory);
    }
}

$composer = (string) file_get_contents($root . '/composer.json');
foreach (['Modules\\\\'] as $legacyPrefix) {
    if (str_contains($composer, $legacyPrefix)) {
        throw new RuntimeException('Legacy PSR-4 prefix was restored: ' . $legacyPrefix);
    }
}

echo "Legacy module removal passed: app/modules and Modules PSR-4 are absent.\n";
