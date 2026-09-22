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
    '<twig:CosEntityHeader',
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
    '<twig:CosEntityHeader',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    'action="/sales/leads"',
    'href="/sales/leads/',
] as $marker) {
    $contains($leads, $marker, 'Lead Inbox cutover lost a canonical Twig or route contract.');
}
$notContains($leads, '/sales/reference/', 'Lead Inbox must not retain reference routes after cutover.');

$pipeline = $read('app/Interfaces/Web/View/sales/pipeline.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/filter_bar'",
    'data-sales-pipeline-root',
    'data-sales-stage-dropzone',
    'data-sales-deal-card',
] as $marker) {
    $contains($pipeline, $marker, 'Sales Pipeline migration lost a canonical or interaction contract.');
}
$notContains($pipeline, '<form class="tn-ui-filter-bar', 'Sales Pipeline must not restore a local filter form.');

$deal = $read('app/Interfaces/Web/View/sales/deal.phtml');
foreach ([
    "partial('components/ui/entity_header'",
    "'identity' =>",
    "'status' =>",
    "'meta' => \$dealMeta",
    'data-sales-deal-workspace',
    'data-sales-stage-form',
] as $marker) {
    $contains($deal, $marker, 'Deal workspace must use canonical entity anatomy without losing behavior.');
}
$notContains($deal, "partial('components/ui/page_header'", 'Deal workspace must not regress from EntityHeader to PageHeader.');


$deals = $read('app/Interfaces/Web/View/sales/deals.phtml');
foreach ([
    "partial('components/ui/filter_bar'",
    "partial('components/ui/data_table'",
    "'responsive' => 'cards'",
] as $marker) {
    $contains($deals, $marker, 'Sales Deals list must use canonical FilterBar and DataTable contracts.');
}
$notContains($deals, '<form class="tn-ui-filter-bar', 'Sales Deals must not restore a local filter form.');
$notContains($deals, 'class="tn-ui-table"', 'Sales Deals must not restore a local raw table.');

$today = $read('app/Interfaces/Web/View/sales/today.phtml');
foreach ([
    "partial('components/ui/panel'",
    "'bodyPartial' => 'components/sales/today_section'",
    'data-sales-today-root',
    'data-sales-today-status',
] as $marker) {
    $contains($today, $marker, 'Sales Today must use canonical Panel composition without losing behavior.');
}
$todaySection = $read('app/Interfaces/Web/View/components/sales/today_section.phtml');
foreach ([
    'data-sales-approval',
    'data-sales-activity-complete',
    'data-sales-activity-reschedule',
] as $marker) {
    $contains($todaySection, $marker, 'Sales Today section must preserve operational interaction contracts.');
}
$notContains($today, '<section class="tn-ui-panel', 'Sales Today must not restore locally assembled panels.');

$director = $read('app/Interfaces/Web/View/sales/director.phtml');
foreach ([
    "partial('components/ui/filter_bar'",
    "partial('components/ui/panel'",
    "'bodyPartial' => 'components/ui/data_table'",
    "partial('components/ui/kpi_card'",
] as $marker) {
    $contains($director, $marker, 'Sales Director must use canonical filter, panel, table and KPI contracts.');
}
$notContains($director, '<form method="get" class="tn-ui-toolbar">', 'Sales Director must not restore a local toolbar.');
$notContains($director, 'class="tn-ui-table"', 'Sales Director must not restore raw local tables.');


$adminLegacySurfaces = [
    'Teams' => $read('app/Interfaces/Web/View/sales_admin/teams.phtml'),
    'Integrations' => $read('app/Interfaces/Web/View/sales_admin/integrations.phtml'),
    'Health & Audit' => $read('app/Interfaces/Web/View/sales_admin/health.phtml'),
];
foreach ($adminLegacySurfaces as $surface => $source) {
    foreach ([
        "partial('components/sales/navigation'",
        "partial('components/ui/page_header'",
    ] as $marker) {
        $contains($source, $marker, 'Sales Admin ' . $surface . ' must use the canonical workspace shell.');
    }
    foreach ([
        'sales-admin-page',
        'sales-admin-header',
        'sales-admin-card',
    ] as $legacyMarker) {
        $notContains($source, $legacyMarker, 'Sales Admin ' . $surface . ' must not restore the legacy administration visual shell.');
    }
}
foreach ([
    "'bodyPartial' => 'components/ui/data_table'",
    'data-sales-team-admin',
    'data-membership-form',
    'data-capabilities-form',
] as $marker) {
    $contains($adminLegacySurfaces['Teams'], $marker, 'Sales Teams migration lost a canonical or behavior contract.');
}
foreach ([
    "partial('components/ui/status_badge'",
    'data-sales-integration-admin',
    'data-create-integration',
    'data-update-integration',
    'data-test-integration',
    'data-route-form',
] as $marker) {
    $contains($adminLegacySurfaces['Integrations'], $marker, 'Sales Integrations migration lost a canonical or behavior contract.');
}
foreach ([
    "partial('components/ui/kpi_card'",
    "partial('components/ui/status_badge'",
    'Operational metrics',
    'Audit timeline',
    'Configuration',
] as $marker) {
    $contains($adminLegacySurfaces['Health & Audit'], $marker, 'Sales Health migration lost a canonical observability contract.');
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
