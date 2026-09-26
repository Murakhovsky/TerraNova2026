<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('WEB V0.15 artifact is missing: ' . $path);
    }
    return (string) file_get_contents($full);
};

$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

$tokens = $read('frontend/styles/tokens.css');
foreach ([
    '--tn-color-workspace: #f5f6f8',
    '--tn-color-graphite: #17191d',
    '--tn-color-accent: #5367ff',
    '--tn-color-accent-soft: #eef0ff',
    '--tn-radius-sm: 6px',
    '--tn-radius-md: 8px',
    '--tn-radius-lg: 10px',
    '--tn-color-positive-soft',
    '--tn-color-warning-soft',
    '--tn-color-danger-soft',
] as $needle) {
    $contains(strtolower($tokens), strtolower($needle), 'Calm Technical token contract is incomplete');
}

foreach (['#f6f3ed', '#c69b4f', '#fbf7ef'] as $legacy) {
    if (str_contains(strtolower($tokens), strtolower($legacy))) {
        throw new RuntimeException('Legacy beige/gold token returned to canonical COS tokens: ' . $legacy);
    }
}

$components = $read('frontend/styles/components.css');
foreach ([
    '.tn-ui-button--primary',
    'var(--tn-color-accent)',
    '.tn-ui-status--positive',
    'var(--tn-color-positive-soft)',
    '.tn-ui-input:focus',
    '.tn-ui-dialog__foot',
] as $needle) {
    $contains($components, $needle, 'Canonical primitives are not aligned with Calm Technical');
}

$patterns = $read('frontend/styles/patterns.css');
foreach (['.tn-ui-table tbody tr:hover td', '.tn-ui-page-head', 'border-bottom: 1px solid var(--tn-color-border)', 'font-size: clamp(24px'] as $needle) {
    $contains($patterns, $needle, 'Canonical enterprise patterns are incomplete');
}

$workspaceLayout = $read('frontend/styles/layouts/workspace.css');
$contains($workspaceLayout, "@import '../workspace-visual-language.css';", 'Workspace surface must load the visual-language layer');

$workspaceVisual = $read('frontend/styles/workspace-visual-language.css');
foreach ([
    'var(--tn-color-graphite)',
    'rgba(83, 103, 255, .16)',
    'var(--tn-color-accent)',
    'var(--tn-color-surface-raised)',
    '.tn-workspace-topbar',
    '.tn-command-palette__panel',
] as $needle) {
    $contains($workspaceVisual, $needle, 'Workspace shell is not fully aligned with Calm Technical');
}

$cos = $read('symfony/templates/experience/operations/control_center.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosToolbar', 'class="cos-kpi-strip"', '<twig:CosEntityListItem', '<twig:CosActionBar'] as $needle) {
    $contains($cos, $needle, 'COS Control Center is not aligned with the canonical Wave 13 visual language');
}
foreach (['tn-', 'style=', '<script'] as $legacy) {
    if (str_contains($cos, $legacy)) {
        throw new RuntimeException('COS Control Center restored legacy/local visual presentation: ' . $legacy);
    }
}

$docs = $read('docs/architecture/web-v0.15.md');
foreach (['Calm Technical', 'Graphite + Cobalt', 'information-dense', 'Canonical primitives', 'Definition of Done'] as $needle) {
    $contains($docs, $needle, 'WEB V0.15 visual constitution is incomplete');
}

echo "WEB V0.15 Calm Technical visual language passed.\n";
