<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root.'/tests/browser/external_reference_quality.mjs';
if (!is_file($file)) throw new RuntimeException('EX-006 browser QA is missing.');

$source = (string) file_get_contents($file);
foreach ([
    'auth-login',
    'cabinet',
    'property-catalog',
    'cos-en',
    'AxeBuilder',
    'internal Workspace sidebar leaked into external UX',
    'horizontal overflow',
    'screenshot',
] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('EX-006 browser QA missing marker: '.$marker);
    }
}

echo "EX-006 browser QA contract OK\n";
