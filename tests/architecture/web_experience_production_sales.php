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

$dashboard = $read('app/Interfaces/Web/View/sales/dashboard.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/data_table'",
    "'responsive' => 'cards'",
] as $marker) {
    $contains($dashboard, $marker, 'Sales Dashboard must use canonical presentation contracts.');
}
$notContains($dashboard, 'class="tn-ui-table"', 'Sales Dashboard must not restore a local raw table.');

$leads = $read('app/Interfaces/Web/View/sales/leads.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/filter_bar'",
    "partial('components/ui/status_badge'",
    'data-sales-lead-status',
    'data-sales-lead-deal',
    'data-sales-lead-followup',
] as $marker) {
    $contains($leads, $marker, 'Lead Inbox migration lost a canonical or behavior contract.');
}
$notContains($leads, '<form class="tn-ui-filter-bar', 'Lead Inbox must not restore a local filter form.');

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
    "'meta' => $dealMeta",
    'data-sales-deal-workspace',
    'data-sales-stage-form',
] as $marker) {
    $contains($deal, $marker, 'Deal workspace must use canonical entity anatomy without losing behavior.');
}
$notContains($deal, "partial('components/ui/page_header'", 'Deal workspace must not regress from EntityHeader to PageHeader.');

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
    'Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 9 documentation is incomplete.');
}

echo "PHASE 9 COS production Sales adoption passed.\n";
