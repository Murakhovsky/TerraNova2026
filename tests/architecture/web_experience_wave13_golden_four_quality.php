<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'tests/browser/golden_four_accessibility.mjs',
    'symfony/assets/styles/business-patterns.css',
    'symfony/assets/styles/workspace-platform.css',
    'symfony/assets/styles/layout.css',
    'symfony/templates/components/experience/cos_context_panel.html.twig',
    'symfony/templates/components/experience/cos_activity_panel.html.twig',
    'symfony/templates/components/experience/cos_ai_context.html.twig',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Phase 2.5 quality artifact is missing: ' . $relative);
    }
}

$archetypes = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
foreach ([
    '$entityWorkspaceResponsive',
    'desktop: workspace header + main + context rail',
    'tablet: shell collapses; main becomes one column; context rail moves below main',
    'mobile: stacked workspace header + single-column context rail + mobile workspace actions',
] as $marker) {
    if (!str_contains($archetypes, $marker)) {
        throw new RuntimeException('Entity Workspace responsive contract is incomplete: ' . $marker);
    }
}

$patterns = (string) file_get_contents($root . '/symfony/src/Web/Experience/Pattern/PatternRegistry.php');
foreach ([
    'desktop lives in the workspace rail; tablet moves below main; mobile becomes a labelled single-column region',
    'panel heading programmatically labels its region',
    "['CosContextPanel']",
] as $marker) {
    if (!str_contains($patterns, $marker)) {
        throw new RuntimeException('ContextPanel consolidated contract is incomplete: ' . $marker);
    }
}
if (str_contains($patterns, "'CosContextPanel', 'CosDrawer'")) {
    throw new RuntimeException('ContextPanel still claims an unproven drawer dependency.');
}

$dealController = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDealController.php');
foreach (["'ActivityFeed'", "'AIRecommendations'"] as $falsePattern) {
    if (str_contains($dealController, $falsePattern)) {
        throw new RuntimeException('Deal Workspace still claims an unrendered Pattern: ' . $falsePattern);
    }
}

$businessCss = (string) file_get_contents($root . '/symfony/assets/styles/business-patterns.css');
foreach ([
    '@media (max-width: 650px)',
    '.cos-page-header',
    '.cos-entity-header',
    '.cos-entity-list-item',
] as $marker) {
    if (!str_contains($businessCss, $marker)) {
        throw new RuntimeException('Golden Four business responsive CSS is incomplete: ' . $marker);
    }
}

$workspaceCss = (string) file_get_contents($root . '/symfony/assets/styles/workspace-platform.css');
foreach ([
    '@media (max-width: 980px)',
    '@media (max-width: 760px)',
    '.cos-workspace__layout',
    '.cos-workspace__rail',
] as $marker) {
    if (!str_contains($workspaceCss, $marker)) {
        throw new RuntimeException('Entity Workspace responsive CSS is incomplete: ' . $marker);
    }
}

$layoutCss = (string) file_get_contents($root . '/symfony/assets/styles/layout.css');
foreach (['@media (max-width: 390px)', '.cos-section-grid'] as $marker) {
    if (!str_contains($layoutCss, $marker)) {
        throw new RuntimeException('Golden Four narrow layout contract is incomplete: ' . $marker);
    }
}

foreach ([
    'cos_context_panel.html.twig',
    'cos_activity_panel.html.twig',
    'cos_ai_context.html.twig',
] as $templateName) {
    $template = (string) file_get_contents($root . '/symfony/templates/components/experience/' . $templateName);
    foreach (['aria-labelledby="{{ id }}-title"', 'id="{{ id }}-title"'] as $marker) {
        if (!str_contains($template, $marker)) {
            throw new RuntimeException($templateName . ' lacks labelled region evidence: ' . $marker);
        }
    }
}

$stateEvidence = [
    'symfony/templates/experience/admin/dashboard.html.twig' => ["page.state == 'permission_denied'", 'dashboard.error', 'dashboard.metrics is empty'],
    'symfony/templates/experience/sales/dashboard.html.twig' => ['dashboard.error', 'dashboard.atRisk is empty', 'dashboard.newLeads is empty'],
    'symfony/templates/experience/sales/leads.html.twig' => ['leads.error', 'leads.items is empty'],
    'symfony/templates/experience/sales/deal_workspace.html.twig' => ['deal.error', 'deal.communications', 'deal.timeline is empty'],
];
foreach ($stateEvidence as $relative => $markers) {
    $template = (string) file_get_contents($root . '/' . $relative);
    foreach ($markers as $marker) {
        if (!str_contains($template, $marker)) {
            throw new RuntimeException('Golden Four state evidence is incomplete: ' . $relative . ' -> ' . $marker);
        }
    }
}

$axe = (string) file_get_contents($root . '/tests/browser/golden_four_accessibility.mjs');
foreach ([
    "from '@axe-core/playwright'",
    '/admin',
    '/sales/dashboard',
    '/sales/leads',
    '/sales/pipeline',
    'executive_dashboard',
    'domain_dashboard',
    'collection',
    'entity_workspace',
    'horizontal overflow',
    'wcag22aa',
    'storageState',
] as $marker) {
    if (!str_contains($axe, $marker)) {
        throw new RuntimeException('Golden Four authenticated accessibility suite is incomplete: ' . $marker);
    }
}

$salesWorkflow = (string) file_get_contents($root . '/.github/workflows/sales.yml');
foreach ([
    'Run Golden Four accessibility evidence',
    'Upload Golden Four accessibility evidence',
    'npm run test:golden-four-accessibility',
    'GOLDEN_FOUR_STORAGE_STATE',
    'golden-four-accessibility',
] as $marker) {
    if (!str_contains($salesWorkflow, $marker)) {
        throw new RuntimeException('Golden Four accessibility workflow evidence is incomplete: ' . $marker);
    }
}

$package = (string) file_get_contents($root . '/package.json');
if (!str_contains($package, '"test:golden-four-accessibility": "node tests/browser/golden_four_accessibility.mjs"')) {
    throw new RuntimeException('Golden Four accessibility npm entrypoint is missing.');
}

echo "Wave 13 Phase 2.5 responsive/state/accessibility normalization passed.\n";
