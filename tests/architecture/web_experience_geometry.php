<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$tokensPath = $root . '/symfony/assets/styles/tokens.css';
$geometryPath = $root . '/symfony/assets/styles/geometry.css';
$appCssPath = $root . '/symfony/assets/styles/app.css';
$styleLabPath = $root . '/symfony/assets/styles/style-lab.css';
$docsPath = $root . '/docs/03-architecture/cos-geometry.md';

foreach ([$tokensPath, $geometryPath, $appCssPath, $styleLabPath, $docsPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('PHASE 4 geometry artifact is missing: ' . $path);
    }
}

$tokens = (string) file_get_contents($tokensPath);

foreach ([
    '--cos-radius-xs: 0.25rem;',
    '--cos-radius-sm: 0.375rem;',
    '--cos-radius-md: 0.5rem;',
    '--cos-radius-lg: 0.625rem;',
    '--cos-radius-pill: 999px;',
    '--cos-border-width: 1px;',
    '--cos-radius-control: var(--cos-radius-sm);',
    '--cos-radius-control-sm: var(--cos-radius-xs);',
    '--cos-radius-panel: var(--cos-radius-md);',
    '--cos-radius-overlay: var(--cos-radius-lg);',
] as $contract) {
    if (!str_contains($tokens, $contract)) {
        throw new RuntimeException('Canonical COS geometry token drifted: ' . $contract);
    }
}

$geometry = (string) file_get_contents($geometryPath);
foreach ([
    '--bs-border-width: var(--cos-border-width);',
    '--bs-border-radius: var(--cos-radius-control);',
    '--bs-border-radius-lg: var(--cos-radius-panel);',
    '--bs-border-radius-xl: var(--cos-radius-overlay);',
    '--bs-border-radius-pill: var(--cos-radius-pill);',
    '--bs-btn-border-radius: var(--cos-radius-control);',
    '--bs-card-border-radius: var(--cos-radius-panel);',
    '--bs-dropdown-border-radius: var(--cos-radius-panel);',
    '--bs-modal-border-radius: var(--cos-radius-overlay);',
] as $contract) {
    if (!str_contains($geometry, $contract)) {
        throw new RuntimeException('Bootstrap geometry bridge is incomplete: ' . $contract);
    }
}

$appCss = (string) file_get_contents($appCssPath);
$tokensImport = strpos($appCss, "@import './tokens.css';");
$geometryImport = strpos($appCss, "@import './geometry.css';");
$primitivesImport = strpos($appCss, "@import './primitives.css';");

if ($tokensImport === false || $geometryImport === false || $primitivesImport === false) {
    throw new RuntimeException('Canonical geometry stylesheet import chain is incomplete.');
}

if (!($tokensImport < $geometryImport && $geometryImport < $primitivesImport)) {
    throw new RuntimeException('geometry.css must load after tokens.css and before canonical component styles.');
}

$styleLab = (string) file_get_contents($styleLabPath);
if (preg_match('/--cos-radius-(?:xs|sm|md|lg|pill)\s*:/', $styleLab) === 1) {
    throw new RuntimeException('Style Lab may not override the frozen canonical geometry scale.');
}

$styleDir = $root . '/symfony/assets/styles';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($styleDir));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'css') {
        continue;
    }

    $name = $file->getFilename();
    $source = (string) file_get_contents($file->getPathname());

    if (!in_array($name, ['tokens.css', 'geometry.css'], true)
        && preg_match('/--cos-radius-[a-z0-9-]+\s*:/i', $source) === 1) {
        throw new RuntimeException('Canonical geometry token redefined outside ownership layer: ' . $name);
    }

    if ($name !== 'tokens.css'
        && preg_match('/border-radius\s*:\s*(?!0(?:\s*;|\s*$))\d*\.?\d+(?:px|rem|em|%)\b/i', $source) === 1) {
        throw new RuntimeException('Raw numeric border-radius leaked into canonical Symfony CSS: ' . $name);
    }
}

$docs = (string) file_get_contents($docsPath);
foreach ([
    'Bootstrap не є власником візуальної геометрії',
    '4 px',
    '6 px',
    '8 px',
    '10 px',
    'Glass',
    'Критерії завершення PHASE 4',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 4 geometry documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 4 COS Geometry contract passed.\n";
