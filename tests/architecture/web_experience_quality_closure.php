<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'tests/browser/web_accessibility.sh',
    'docs/03-architecture/cos-quality-closure.md',
    '.github/workflows/symfony-bootstrap.yml',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Missing PHASE 15 artifact: ' . $relative);
    }
}

$script = (string) file_get_contents($root . '/tests/browser/web_accessibility.sh');
foreach ([
    '@axe-core/cli@',
    '4.13.0',
    'wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa',
    'scan home /',
    'scan login /auth/login',
    'scan property-catalog /property/catalog',
    '--exit',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException('PHASE 15 accessibility executable contract is incomplete: ' . $marker);
    }
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-quality-closure.md');
foreach ([
    'PHASE 15 — Quality Closure',
    'Accessibility contract',
    'WCAG 2.2 AA',
    'Freeze boundary',
    'Критерії завершення',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 15 documentation is incomplete: ' . $marker);
    }
}

$workflow = (string) file_get_contents($root . '/.github/workflows/symfony-bootstrap.yml');
foreach ([
    'PHASE 15 quality closure gate',
    'PHASE 15 WCAG accessibility',
    'Upload PHASE 15 accessibility evidence',
] as $marker) {
    if (!str_contains($workflow, $marker)) {
        throw new RuntimeException('PHASE 15 CI coverage is incomplete: ' . $marker);
    }
}

echo "PHASE 15 Quality Closure passed.\n";
