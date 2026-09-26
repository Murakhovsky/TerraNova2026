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

$property = $read('symfony/templates/experience/property/submissions.html.twig');
foreach ([
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    'data-property-submissions',
] as $needle) {
    $requireContains($property, $needle, 'Property submissions must use canonical Operational Queue contracts.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $legacy) {
    $requireNotContains($property, $legacy, 'Property submissions must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/property/submissions.phtml')) {
    throw new RuntimeException('Retired Property submissions PHTML restored.');
}

$analytics = $read('symfony/templates/experience/administration/analytics.html.twig');
foreach (['<twig:CosPageHeader','<twig:CosFilterBar','class="cos-kpi-strip"','<twig:CosDataGrid'] as $marker) {
    $contains($analytics, $marker, 'Administration Analytics Wave 13 contract is incomplete.');
}
foreach (['tn-','style=','<script'] as $legacy) {
    $notContains($analytics, $legacy, 'Administration Analytics must not restore legacy presentation.');
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

$cos = $read('symfony/templates/experience/system/control_center.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'data-cos-control-center',
] as $needle) {
    $requireContains($cos, $needle, 'COS Control Center must use canonical System Control Surface contracts.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $legacy) {
    $requireNotContains($cos, $legacy, 'COS Control Center must not restore legacy/local presentation.');
}

$clientSurfaces = [
    'symfony/templates/experience/client_case/inbox.html.twig' => [
        '<twig:CosPageHeader',
        '<twig:CosFilterBar',
        '<twig:ClientCaseInboxItem',
        'data-client-case-inbox',
    ],
    'symfony/templates/experience/client_case/index.html.twig' => [
        '<twig:CosPageHeader',
        '<twig:CosFilterBar',
        '<twig:ClientCaseFunnel',
        '<twig:ClientCaseCollectionItem',
        'data-client-case-collection',
    ],
    'symfony/templates/experience/client_case/show.html.twig' => [
        '<twig:CosWorkspace',
        '<twig:CosEntityHeader',
        '<twig:CosTimeline',
        'data-client-case-workspace',
    ],
];
foreach ($clientSurfaces as $path => $needles) {
    $view=$read($path);
    foreach($needles as $needle)$requireContains($view,$needle,'Client Case canonical Twig composition incomplete: '.$path);
    foreach(['tn-','style=','<script'] as $legacy)$requireNotContains($view,$legacy,'Client Case Twig must not restore legacy/local presentation: '.$path);
}
foreach ([
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
] as $retired) {
    if (file_exists($root . '/' . $retired)) throw new RuntimeException('Retired Client Case artifact restored: ' . $retired);
}
$clientCss=$read('symfony/assets/styles/domains/client-case.css');
$requireContains($clientCss,'.cos-client-case-funnel','Canonical Client Case domain CSS must retain funnel visualization.');
$requireNotContains($clientCss,'tn-','Canonical Client Case domain CSS must not restore TN selectors.');

$kpi = $read('app/Interfaces/Web/View/components/ui/kpi_card.phtml');
$components = $read('frontend/styles/components.css');
$requireContains($kpi, "['neutral', 'brand', 'positive', 'warning', 'danger']", 'KPI tone API must remain closed and semantic.');
$requireContains($components, '.tn-ui-kpi--brand', 'Canonical KPI brand tone must have a visual contract.');
$requireContains($components, 'var(--tn-color-accent-border)', 'KPI brand tone must be owned by COS design tokens.');

$docs = $read('docs/architecture/web-v0.17.md');
foreach (['Workspace Canonicalization', 'Property Submissions', 'Analytics', 'Diagnostic Report', 'COS Control Center', 'Client Case', 'Wave 13 Phase 4', 'Завершення WEB V0.17'] as $needle) {
    $requireContains($docs, $needle, 'WEB V0.17 documentation is incomplete.');
}

echo "WEB V0.17 workspace canonicalization passed.\n";
