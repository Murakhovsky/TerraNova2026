<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/assets/styles/style-lab.css',
    'symfony/templates/experience/_style_lab_switcher.html.twig',
    'docs/03-architecture/cos-style-lab.md',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('PHASE 2 Style Lab artifact is missing: ' . $relative);
    }
}

$styleLab = (string) file_get_contents($root . '/symfony/assets/styles/style-lab.css');

foreach ([
    'data-cos-style-lab="origin-a"',
    'data-cos-style-lab="origin-b"',
    'data-cos-style-lab="origin-c"',
    'data-cos-style-lab="glass"',
    'backdrop-filter',
    '--cos-style-lab-canvas-image',
    '--cos-color-primary',
] as $marker) {
    if (!str_contains($styleLab, $marker)) {
        throw new RuntimeException('PHASE 2 Style Lab CSS contract is missing: ' . $marker);
    }
}

if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styleLab) === 1) {
    throw new RuntimeException('Style Lab must compose semantic/foundation tokens instead of raw hex values.');
}

$switcher = (string) file_get_contents($root . '/symfony/templates/experience/_style_lab_switcher.html.twig');
foreach ([
    'data-style="light"',
    'data-style="dark"',
    'data-style="origin-a"',
    'data-style="origin-b"',
    'data-style="origin-c"',
    'data-style="glass"',
    'data-action="click->appearance#setStyle"',
    'data-appearance-target="styleButton"',
] as $marker) {
    if (!str_contains($switcher, $marker)) {
        throw new RuntimeException('Style Lab switcher contract is missing: ' . $marker);
    }
}

$appearance = (string) file_get_contents($root . '/symfony/assets/controllers/appearance_controller.js');
foreach ([
    'setStyle',
    'cosStyleLab',
    'origin-a',
    'origin-b',
    'origin-c',
    'glass',
    'styleButtonTargets',
] as $marker) {
    if (!str_contains($appearance, $marker)) {
        throw new RuntimeException('Appearance controller Style Lab contract is missing: ' . $marker);
    }
}

foreach (['localStorage', 'sessionStorage', 'fetch(', '/api/'] as $forbidden) {
    if (str_contains($appearance, $forbidden)) {
        throw new RuntimeException('Style Lab appearance controller must remain presentation-only: ' . $forbidden);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
$workspace = (string) file_get_contents($root . '/symfony/templates/experience/workspace_platform_preview.html.twig');

foreach ([$catalog, $workspace] as $surface) {
    if (!str_contains($surface, '_style_lab_switcher.html.twig')) {
        throw new RuntimeException('Style Lab switcher must be exposed on both canonical reference surfaces.');
    }

    if (!str_contains($surface, "asset('styles/style-lab.css')")) {
        throw new RuntimeException('Style Lab stylesheet must remain explicitly dev-surface scoped.');
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (str_contains($appCss, 'style-lab.css')) {
    throw new RuntimeException('PHASE 2 experimental Style Lab must not ship through the production app.css bundle.');
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-style-lab.md');
foreach ([
    'Origin A',
    'Origin B',
    'Origin C',
    'Executive Glass',
    'glass for structure, opacity for information',
    'Критерії завершення PHASE 2',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('Style Lab documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 2 COS Style Lab passed.\n";
