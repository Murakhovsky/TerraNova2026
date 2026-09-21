<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'docs/11-decisions/ADR-0010-web-ui-foundation-freeze.md',
    'docs/03-architecture/cos-visual-constitution.md',
    'symfony/assets/styles/tokens.css',
    'symfony/assets/styles/primitives.css',
    'symfony/assets/styles/interactions.css',
    'symfony/assets/styles/forms.css',
    'symfony/assets/styles/data-grid.css',
    'symfony/assets/styles/workspace-platform.css',
    'symfony/assets/styles/async-operations.css',
    'symfony/assets/styles/ai-ui.css',
    'symfony/assets/styles/shell.css',
    'symfony/importmap.php',
    'package.json',
];

foreach ($requiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Visual foundation artifact is missing: ' . $relative);
    }
}

$adr = (string) file_get_contents($root . '/docs/11-decisions/ADR-0010-web-ui-foundation-freeze.md');
foreach ([
    'Поточна Symfony Web Experience Platform заморожується',
    'Semantic tokens',
    'Twig Components',
    'Stimulus',
    'Turbo',
    'нового ADR',
    'Domain не може перевизначати',
] as $marker) {
    if (!str_contains($adr, $marker)) {
        throw new RuntimeException('PHASE 0 foundation freeze contract is incomplete: ' . $marker);
    }
}

$constitution = (string) file_get_contents($root . '/docs/03-architecture/cos-visual-constitution.md');
foreach ([
    'Calm Technical',
    'Control',
    'Money',
    'Movement',
    'Origin',
    'Light',
    'Dark',
    'Financial visual language',
    'Entity visual language',
    'AI visual language',
    'Accessibility',
    'Responsive philosophy',
    'Definition of Done PHASE 1',
] as $marker) {
    if (!str_contains($constitution, $marker)) {
        throw new RuntimeException('COS Visual Constitution is incomplete: ' . $marker);
    }
}

$package = json_decode(
    (string) file_get_contents($root . '/package.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$declaredPackages = array_change_key_case(
    array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []),
    CASE_LOWER,
);

$forbiddenFrameworks = [
    'react',
    'react-dom',
    'vue',
    '@angular/core',
    'svelte',
    'alpinejs',
    'jquery',
];

foreach ($forbiddenFrameworks as $framework) {
    if (array_key_exists(strtolower($framework), $declaredPackages)) {
        throw new RuntimeException(
            'PHASE 0 foundation freeze forbids a new global frontend framework without ADR: ' . $framework,
        );
    }
}

$importmap = (string) file_get_contents($root . '/symfony/importmap.php');

foreach ([
    "'@hotwired/stimulus'",
    "'@hotwired/turbo'",
    "'@symfony/ux-live-component'",
    "'bootstrap'",
] as $requiredRuntime) {
    if (!str_contains($importmap, $requiredRuntime)) {
        throw new RuntimeException('Canonical Symfony UI runtime dependency is missing: ' . $requiredRuntime);
    }
}

foreach ([
    "'react'",
    "'react-dom'",
    "'vue'",
    "'@angular/core'",
    "'svelte'",
    "'alpinejs'",
    "'jquery'",
] as $forbiddenRuntime) {
    if (str_contains($importmap, $forbiddenRuntime . ' =>')) {
        throw new RuntimeException(
            'PHASE 0 foundation freeze forbids a new global importmap runtime without ADR: ' . $forbiddenRuntime,
        );
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
foreach ([
    "@import './tokens.css';",
    "@import './primitives.css';",
    "@import './interactions.css';",
    "@import './forms.css';",
    "@import './data-grid.css';",
    "@import './workspace-platform.css';",
    "@import './async-operations.css';",
    "@import './ai-ui.css';",
    "@import './shell.css';",
] as $canonicalLayer) {
    if (!str_contains($appCss, $canonicalLayer)) {
        throw new RuntimeException('Canonical visual foundation layer is missing: ' . $canonicalLayer);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
if (!str_contains($catalog, 'COS Visual Constitution')) {
    throw new RuntimeException('Design System catalog does not expose the Visual Constitution.');
}

$decisionsIndex = (string) file_get_contents($root . '/docs/11-decisions/README.md');
if (!str_contains($decisionsIndex, 'ADR-0010-web-ui-foundation-freeze.md')) {
    throw new RuntimeException('ADR-0010 is missing from the decisions index.');
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
foreach ([
    '--cos-color-canvas',
    '--cos-color-surface',
    '--cos-color-text',
    '--cos-color-primary',
    '--cos-space-',
    '--cos-radius-',
    '--cos-shadow-',
    '--cos-motion-',
    '--cos-control-height',
    '--cos-density-',
] as $semanticFamily) {
    if (!str_contains($tokens, $semanticFamily)) {
        throw new RuntimeException('Canonical semantic token family is missing: ' . $semanticFamily);
    }
}

echo "COS Visual Foundation freeze and Constitution passed.\n";
