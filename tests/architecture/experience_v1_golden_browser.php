<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/tests/browser/golden_eight_quality.mjs';

if (!is_file($file)) {
    throw new RuntimeException('Golden Eight browser QA is missing.');
}

$source = (string) file_get_contents($file);
foreach ([
    'executive-dashboard',
    'sales-dashboard',
    'sales-today',
    'sales-leads',
    'sales-deal',
    'sales-pipeline',
    'growth-overview',
    'property-map',
    'AxeBuilder',
    'desktop',
    'mobile',
    'horizontal overflow',
    'screenshot',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('Golden Eight browser QA missing marker: ' . $marker);
    }
}

echo "Golden Eight browser QA contract OK\n";
