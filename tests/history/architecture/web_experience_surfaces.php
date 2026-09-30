<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$paths = [
    'symfony/assets/styles/tokens.css',
    'symfony/assets/styles/surfaces.css',
    'symfony/assets/styles/app.css',
    'symfony/assets/styles/primitives.css',
    'symfony/assets/styles/interactions.css',
    'symfony/assets/styles/forms.css',
    'symfony/assets/styles/data-grid.css',
    'symfony/assets/styles/workspace-platform.css',
    'symfony/assets/styles/shell.css',
    'symfony/assets/styles/async-operations.css',
    'symfony/assets/styles/ai-ui.css',
    'symfony/templates/experience/design_system_catalog.html.twig',
    'docs/03-architecture/cos-surfaces.md',
];

foreach ($paths as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('PHASE 5 surface artifact is missing: ' . $relative);
    }
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
foreach ([
    '--cos-surface-canvas-bg: var(--cos-color-canvas);',
    '--cos-surface-default-bg: var(--cos-color-surface);',
    '--cos-surface-subtle-bg: var(--cos-color-surface-subtle);',
    '--cos-surface-raised-bg: var(--cos-color-surface-raised);',
    '--cos-surface-floating-bg: var(--cos-color-surface-raised);',
    '--cos-surface-overlay-bg: var(--cos-color-surface);',
    '--cos-surface-default-shadow: none;',
    '--cos-surface-raised-shadow: none;',
    '--cos-surface-floating-shadow: var(--cos-shadow-raised);',
    '--cos-surface-overlay-shadow: var(--cos-shadow-overlay);',
] as $contract) {
    if (!str_contains($tokens, $contract)) {
        throw new RuntimeException('COS surface token contract drifted: ' . $contract);
    }
}

$surfaces = (string) file_get_contents($root . '/symfony/assets/styles/surfaces.css');
foreach ([
    '.cos-surface--canvas',
    '.cos-surface--default',
    '.cos-surface--subtle',
    '.cos-surface--raised',
    '.cos-surface--floating',
    '.cos-surface--overlay',
    '--bs-card-bg: var(--cos-surface-default-bg);',
    '--bs-dropdown-bg: var(--cos-surface-floating-bg);',
    'background: var(--cos-surface-overlay-bg);',
] as $contract) {
    if (!str_contains($surfaces, $contract)) {
        throw new RuntimeException('COS surface bridge is incomplete: ' . $contract);
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
$geometryImport = strpos($appCss, "@import './geometry.css';");
$surfaceImport = strpos($appCss, "@import './surfaces.css';");
$primitivesImport = strpos($appCss, "@import './primitives.css';");

if ($geometryImport === false || $surfaceImport === false || $primitivesImport === false) {
    throw new RuntimeException('COS surface import chain is incomplete.');
}

if (!($geometryImport < $surfaceImport && $surfaceImport < $primitivesImport)) {
    throw new RuntimeException('surfaces.css must load after geometry and before canonical component CSS.');
}

$primitives = (string) file_get_contents($root . '/symfony/assets/styles/primitives.css');
if (!str_contains($primitives, 'box-shadow: var(--cos-surface-default-shadow);')) {
    throw new RuntimeException('Persistent COS Card still owns decorative elevation.');
}
if (!str_contains($primitives, 'background: var(--cos-surface-raised-bg);')) {
    throw new RuntimeException('Raised COS Card must express hierarchy through surface contrast.');
}
if (!str_contains($primitives, 'box-shadow: var(--cos-surface-raised-shadow);')) {
    throw new RuntimeException('Raised COS Card shadow contract is missing.');
}

$interaction = (string) file_get_contents($root . '/symfony/assets/styles/interactions.css');
foreach ([
    'background: var(--cos-surface-overlay-bg);',
    'box-shadow: var(--cos-surface-overlay-shadow);',
    'background: var(--cos-surface-floating-bg);',
    'box-shadow: var(--cos-surface-floating-shadow);',
] as $contract) {
    if (!str_contains($interaction, $contract)) {
        throw new RuntimeException('Interaction surface mapping is incomplete: ' . $contract);
    }
}

foreach ([
    'symfony/assets/styles/forms.css',
    'symfony/assets/styles/data-grid.css',
    'symfony/assets/styles/workspace-platform.css',
] as $floatingFile) {
    $source = (string) file_get_contents($root . '/' . $floatingFile);
    if (!str_contains($source, 'var(--cos-surface-floating-bg)')
        || !str_contains($source, 'var(--cos-surface-floating-shadow)')) {
        throw new RuntimeException('Floating menu surface mapping is incomplete: ' . $floatingFile);
    }
}

foreach ([
    'symfony/assets/styles/shell.css',
    'symfony/assets/styles/async-operations.css',
    'symfony/assets/styles/ai-ui.css',
] as $overlayFile) {
    $source = (string) file_get_contents($root . '/' . $overlayFile);
    if (!str_contains($source, 'var(--cos-surface-overlay-bg)')
        || !str_contains($source, 'var(--cos-surface-overlay-shadow)')) {
        throw new RuntimeException('Overlay surface mapping is incomplete: ' . $overlayFile);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'Surface hierarchy',
    'cos-surface--canvas',
    'cos-surface--default',
    'cos-surface--subtle',
    'cos-surface--raised',
    'cos-surface--floating',
    'cos-surface--overlay',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('/dev/ui does not expose the full surface hierarchy: ' . $marker);
    }
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-surfaces.md');
foreach ([
    'Canvas',
    'Default Surface',
    'Subtle Surface',
    'Raised Surface',
    'Floating Surface',
    'Overlay Surface',
    'glass for structure, opacity for information',
    'Критерії завершення PHASE 5',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 5 surface documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 5 COS Surface Hierarchy passed.\n";
