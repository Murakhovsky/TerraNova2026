<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('PHASE 9 artifact is missing: ' . $path);
    }

    return (string) file_get_contents($full);
};

$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
};

$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) {
        throw new RuntimeException($message . ' Forbidden: ' . $needle);
    }
};

$dashboard = $read('symfony/templates/experience/sales/dashboard.html.twig');
foreach ([
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosTrendMetric',
    '<twig:CosMoneyMetric',
    '<twig:CosEntityListItem',
    '<twig:CosNextAction',
    'href="/sales/leads"',
] as $marker) {
    $contains($dashboard, $marker, 'Sales Dashboard must use canonical Twig presentation contracts after Wave 12.26 cutover.');
}
$notContains($dashboard, '/sales/reference/', 'Sales Dashboard must not retain reference routes after cutover.');

$leads = $read('symfony/templates/experience/sales/leads.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    'action="/sales/leads"',
    'href="/sales/leads/',
] as $marker) {
    $contains($leads, $marker, 'Lead Inbox cutover lost a canonical Twig or route contract.');
}
$notContains($leads, '/sales/reference/', 'Lead Inbox must not retain reference routes after cutover.');

$pipeline = $read('symfony/templates/experience/sales/pipeline.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosFilterBar',
    '<twig:SalesPipelineBoard',
    'data-controller="sales-pipeline"',
    'data-sales-pipeline-root',
] as $marker) {
    $contains($pipeline, $marker, 'Sales Pipeline must use canonical Process/Pipeline composition.');
}
$pipelineBoard = $read('symfony/templates/components/sales/sales_pipeline_board.html.twig');
foreach ([
    'data-sales-stage-dropzone',
    'data-sales-deal-card',
    'draggable="true"',
    'submit->sales-pipeline#changeStage',
] as $marker) {
    $contains($pipelineBoard, $marker, 'Sales Pipeline domain board lost an interaction contract.');
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    $notContains($pipeline, $forbidden, 'Sales Pipeline must not restore legacy/local page presentation.');
    $notContains($pipelineBoard, $forbidden, 'Sales Pipeline board must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/sales/pipeline.phtml')) {
    throw new RuntimeException('Legacy Sales Pipeline PHTML must stay retired after VR-006.');
}

$deal = $read('symfony/templates/experience/sales/deal_workspace.html.twig');
foreach ([
    '<twig:CosWorkspace',
    '<twig:CosEntityHeader',
    'data-controller="sales-deal"',
    'data-sales-deal-workspace',
    'data-sales-stage-form',
    'data-sales-operation-form',
    'data-sales-intelligence',
    '<twig:CosTimeline',
] as $marker) {
    $contains($deal, $marker, 'Deal Workspace must use canonical Entity Workspace composition without losing behavior.');
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    $notContains($deal, $forbidden, 'Deal Workspace must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/sales/deal.phtml')) {
    throw new RuntimeException('Legacy Deal Workspace PHTML must stay retired after VR-004.');
}


$deals = $read('symfony/templates/experience/sales/deals.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosDataGrid',
    'data-controller="sales-deals"',
    'cos:datagrid-row-action->sales-deals#rowAction',
] as $marker) {
    $contains($deals, $marker, 'Sales Deals must use canonical Collection/DataGrid composition.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    $notContains($deals, $forbidden, 'Sales Deals page must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/sales/deals.phtml')) {
    throw new RuntimeException('Legacy Sales Deals PHTML must stay retired after VR-007.');
}

$today = $read('symfony/templates/experience/sales/today.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    '<twig:CosEmptyState',
    'data-controller="sales-today"',
    'data-sales-today-root',
    'data-sales-today-status',
    'data-sales-approval',
    'data-sales-activity-complete',
    'data-sales-activity-reschedule',
] as $marker) {
    $contains($today, $marker, 'Sales Today must use canonical Operational Queue composition without losing behavior.');
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    $notContains($today, $forbidden, 'Sales Today must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/sales/today.phtml')) {
    throw new RuntimeException('Legacy Sales Today PHTML must stay retired after VR-005.');
}
if (is_file($root . '/app/Interfaces/Web/View/components/sales/today_section.phtml')) {
    throw new RuntimeException('Legacy Sales Today section PHTML must stay retired after VR-005.');
}

$director = $read('symfony/templates/experience/sales/director.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    'class="cos-kpi-strip"',
    '<twig:CosDataGrid',
    'Historical Sales Intelligence',
    'Pipeline and forecast by currency',
    'Historical funnel',
    'Manager performance',
    'Risk & explainability',
] as $marker) {
    $contains($director, $marker, 'Sales Director must use canonical Executive Dashboard composition.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    $notContains($director, $forbidden, 'Sales Director must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/sales/director.phtml')) {
    throw new RuntimeException('Legacy Sales Director PHTML must stay retired after VR-008.');
}


$adminSurfaces = [
    'Dashboard' => $read('symfony/templates/experience/sales/admin/dashboard.html.twig'),
    'Teams' => $read('symfony/templates/experience/sales/admin/teams.html.twig'),
    'Integrations' => $read('symfony/templates/experience/sales/admin/integrations.html.twig'),
    'Health & Audit' => $read('symfony/templates/experience/sales/admin/health.html.twig'),
    'Rule Editor' => $read('symfony/templates/experience/sales/admin/rule.html.twig'),
];
foreach ($adminSurfaces as $surface => $source) {
    foreach (['<twig:CosPageHeader'] as $marker) {
        $contains($source, $marker, 'Sales Admin ' . $surface . ' must use canonical Twig shell composition.');
    }
    foreach (['tn-', 'style=', '<script'] as $legacyMarker) {
        $notContains($source, $legacyMarker, 'Sales Admin ' . $surface . ' must not restore legacy presentation.');
    }
}
foreach ([
    'data-controller="sales-admin-teams"',
    'data-membership-form',
    'data-capabilities-form',
] as $marker) {
    $contains($adminSurfaces['Teams'], $marker, 'Sales Teams migration lost a canonical or behavior contract.');
}
foreach ([
    'data-controller="sales-admin-integrations"',
    'data-create-integration',
    'data-update-integration',
    'data-route-form',
] as $marker) {
    $contains($adminSurfaces['Integrations'], $marker, 'Sales Integrations migration lost a canonical or behavior contract.');
}
foreach ([
    'class="cos-kpi-strip"',
    'Operational queue',
    'Audit timeline',
    'Configuration',
] as $marker) {
    $contains($adminSurfaces['Health & Audit'], $marker, 'Sales Health migration lost a canonical observability contract.');
}
foreach ([
    'data-controller="sales-admin-rule-editor"',
    'sales-admin-rule-editor#save',
    'sales-admin-rule-editor#dryRun',
] as $marker) {
    $contains($adminSurfaces['Rule Editor'], $marker, 'Sales Rule editor migration lost canonical behavior.');
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') continue;
    $relative = str_replace('\\', '/', substr($view->getPathname(), strlen($root) + 1));
    if (str_contains($relative, '/sales/') || str_contains($relative, '/sales_admin/')) {
        throw new RuntimeException('Phase 3 Sales visual PHTML must be zero: ' . $relative);
    }
}

$filterBar = $read('app/Interfaces/Web/View/components/ui/filter_bar.phtml');
foreach ([
    "'number'",
    '$hidden = is_array',
    'type="hidden"',
    'isset($field[\'min\'])',
    'isset($field[\'step\'])',
] as $marker) {
    $contains($filterBar, $marker, 'Canonical PHTML FilterBar compatibility contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-production-sales-adoption.md');
foreach ([
    'Сумісний перехідний шар',
    'Панель продажів (`Sales Dashboard`)',
    'Вхідні ліди (`Lead Inbox`)',
    'Воронка продажів (`Sales Pipeline`)',
    'Робочий простір угоди (`Deal Workspace`)',
    'Хвиля 2',
    'Список угод (`Deals`)',
    'Операційний inbox (`Today`)',
    'Робочий простір директора (`Director Workspace`)',
    'Хвиля 3',
    'Команди та повноваження (`Teams & Authority`)',
    'Інтеграції (`Integrations`)',
    'Стан та аудит (`Health & Audit`)',
    'Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 9 documentation is incomplete.');
}

echo "PHASE 9 COS production Sales adoption passed.\n";
