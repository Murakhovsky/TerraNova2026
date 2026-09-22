<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('Missing WEB V0.17 artifact: ' . $path);
    }
    return (string) file_get_contents($full);
};

$requireContains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
};

$requireNotContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) {
        throw new RuntimeException($message . ' Forbidden: ' . $needle);
    }
};

$property = $read('app/Interfaces/Web/View/property/submissions.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/data_table'",
    'tn-ui-panel--flush',
] as $needle) {
    $requireContains($property, $needle, 'Property submissions must use canonical UI contracts.');
}
foreach (['tn-page-hero', 'tn-listing-table', 'tn-empty-state', 'tn-status-pill'] as $legacy) {
    $requireNotContains($property, $legacy, 'Property submissions must not restore the legacy workspace presentation pattern.');
}

$analytics = $read('app/Interfaces/Web/View/admin/analytics.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/filter_bar'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/data_table'",
    'tn-ui-panel--flush',
] as $needle) {
    $requireContains($analytics, $needle, 'Analytics must use canonical UI contracts.');
}
foreach (['tn-page-hero', 'tn-admin-metrics', 'tn-admin-card', 'tn-listing-table', 'tn-table-wrap'] as $legacy) {
    $requireNotContains($analytics, $legacy, 'Analytics must not restore the legacy workspace presentation pattern.');
}

$diagnostic = $read('app/Interfaces/Web/View/diagnostic_report/show.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/kpi_card'",
    'tn-ui-panel',
    'tn-ui-panel__head',
] as $needle) {
    $requireContains($diagnostic, $needle, 'Diagnostic report must use canonical UI contracts.');
}
foreach (['tn-page-header', 'tn-card-grid', 'class="tn-card"'] as $legacy) {
    $requireNotContains($diagnostic, $legacy, 'Diagnostic report must not restore legacy card/header primitives.');
}

$cos = $read('app/Interfaces/Web/View/cos/index.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/tabs'",
    "partial('components/ui/status_badge'",
    'tn-ui-panel',
    'tn-workspace-page--wide',
] as $needle) {
    $requireContains($cos, $needle, 'COS Control Center must use canonical shell contracts.');
}
foreach (['tn-listing-hero', 'tn-cos-metrics', 'tn-admin-tabs', 'tn-admin-panel', 'tn-cos-status--'] as $legacy) {
    $requireNotContains($cos, $legacy, 'COS Control Center must not restore its legacy shell/status primitives.');
}

$clientCss = $read('frontend/features/clients/workspace.css');
$clientEntrypoint = $read('frontend/entrypoints/clients-workspace.js');
foreach ([
    '.tn-client-workspace',
    '.tn-inbox-card',
    '.tn-case-funnel',
    '.tn-ai-deal-card',
    'var(--tn-color-accent)',
    '@media (max-width: 650px)',
] as $needle) {
    $requireContains($clientCss, $needle, 'Client Case domain CSS must retain live specialized patterns and COS tokens.');
}
foreach ([
    'Compatibility bridge while Client Case PHTML moves to canonical components.',
    'tn-listing-hero',
    'tn-admin-metrics',
    'tn-admin-tabs',
    'tn-admin-panel',
    'tn-empty-state',
    'tn-section-heading',
] as $legacy) {
    $requireNotContains($clientCss, $legacy, 'Client Case CSS must not restore retired compatibility selectors.');
}
$requireContains($clientEntrypoint, "../features/clients/workspace.css", 'Client Case Vite entrypoint must retain domain CSS.');
$requireNotContains($clientEntrypoint, "../features/clients/workspace.js", 'Client Case Vite entrypoint must not restore the retired scoping script.');

foreach ([
    'app/Interfaces/Web/View/client_case/inbox.phtml' => [
        "partial('components/ui/page_header'",
        "partial('components/ui/state'",
        "partial('components/ui/kpi_card'",
        "partial('components/ui/tabs'",
        "partial('components/ui/filter_bar'",
    ],
    'app/Interfaces/Web/View/client_case/index.phtml' => [
        "partial('components/ui/page_header'",
        "partial('components/ui/state'",
        "partial('components/ui/tabs'",
        "partial('components/ui/filter_bar'",
        "partial('components/ui/operational_grid'",
        '$caseRows = [];',
        "'_form' => [",
    ],
    'app/Interfaces/Web/View/client_case/show.phtml' => [
        "partial('components/ui/entity_header'",
        "partial('components/ui/state'",
        "partial('components/ui/kpi_card'",
        'tn-ui-panel',
    ],
] as $path => $needles) {
    $view = $read($path);
    foreach ($needles as $needle) {
        $requireContains($view, $needle, 'Client Case view is missing canonical production composition: ' . $path);
    }
    foreach (['tn-listing-hero', 'tn-admin-panel', 'tn-empty-state', 'tn-breadcrumbs'] as $legacy) {
        $requireNotContains($view, $legacy, 'Client Case view must not restore compatibility presentation primitives: ' . $path);
    }
}

$kpi = $read('app/Interfaces/Web/View/components/ui/kpi_card.phtml');
$components = $read('frontend/styles/components.css');
$requireContains($kpi, "['neutral', 'brand', 'positive', 'warning', 'danger']", 'KPI tone API must remain closed and semantic.');
$requireContains($components, '.tn-ui-kpi--brand', 'Canonical KPI brand tone must have a visual contract.');
$requireContains($components, 'var(--tn-color-accent-border)', 'KPI brand tone must be owned by COS design tokens.');

$docs = $read('docs/architecture/web-v0.17.md');
foreach (['Workspace Canonicalization', 'Property Submissions', 'Analytics', 'Diagnostic Report', 'COS Control Center', 'Client Case', 'PHASE 12', 'Завершення WEB V0.17'] as $needle) {
    $requireContains($docs, $needle, 'WEB V0.17 documentation is incomplete.');
}

echo "WEB V0.17 workspace canonicalization passed.\n";
