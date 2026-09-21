<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing required file: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$compare = $read('app/Interfaces/Web/View/property/compare.phtml');
foreach ([
    "partial('components/ui/data_table'",
    "partial('components/ui/state'",
    "'responsive' => 'cards'",
    'data-save-property',
    'data-toggle-text',
] as $marker) {
    $contains($compare, $marker, 'Property Compare must use canonical table/state contracts without losing selection behavior.');
}
$notContains($compare, 'tn-listing-table tn-compare-table', 'Property Compare must not restore the legacy comparison table.');

$manage = $read('app/Interfaces/Web/View/property/manage.phtml');
foreach ([
    "partial('components/ui/filter_bar'",
    "'name' => 'operational_stage'",
    "'name' => 'quality'",
    "'name' => 'sort'",
    'tn-inline-status-form',
] as $marker) {
    $contains($manage, $marker, 'Property Manage must use canonical filters while preserving editable operational behavior.');
}
$notContains($manage, '<form class="tn-manage-filters"', 'Property Manage must not restore the legacy local filter form.');

$listing = $read('app/Interfaces/Web/View/property/listing.phtml');
foreach ([
    "partial('components/ui/filter_bar'",
    "'name' => 'property_group_id'",
    "'name' => 'agent_id'",
    "'name' => 'visibility'",
    "'name' => 'sale_priority'",
    'listing-form-',
] as $marker) {
    $contains($listing, $marker, 'Property Listing must use canonical filters while preserving inventory editing behavior.');
}
$notContains($listing, '<form class="tn-crm-filters"', 'Property Listing must not restore the legacy local filter form.');

$group = $read('app/Interfaces/Web/View/property/group.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/panel'",
    "'bodyPartial' => 'components/ui/data_table'",
    "'responsive' => 'cards'",
    'property/edit/',
    'property/show/',
] as $marker) {
    $contains($group, $marker, 'Property Group must use canonical workspace/table contracts while preserving object navigation.');
}
$notContains($group, 'tn-listing-table tn-manage-table', 'Property Group must not restore the legacy object table.');

$add = $read('app/Interfaces/Web/View/property/add.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'property/add',
    'enctype="multipart/form-data"',
] as $marker) {
    $contains($add, $marker, 'Property Add must use the canonical form shell without losing create behavior.');
}
foreach (['tn-page-hero tn-page-hero--catalog', 'tn-admin-card', 'tn-admin-card__head'] as $legacyMarker) {
    $notContains($add, $legacyMarker, 'Property Add must not restore the legacy visual shell.');
}

$edit = $read('app/Interfaces/Web/View/property/edit.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'data-copy-value',
    'property/quick/',
    'property/presentationShare',
    '#tn-edit-main',
    '#tn-edit-media',
    '#tn-edit-public',
    '#tn-edit-service',
] as $marker) {
    $contains($edit, $marker, 'Property Edit must use the canonical form shell without losing editor behavior.');
}
foreach (['tn-page-hero tn-page-hero--catalog', 'tn-admin-card', 'tn-admin-card__head'] as $legacyMarker) {
    $notContains($edit, $legacyMarker, 'Property Edit must not restore the legacy visual shell.');
}

$actionBar = $read('app/Interfaces/Web/View/components/ui/action_bar.phtml');
foreach ([
    "foreach ($attributes as $name => $value)",
    "href="<?php echo $h($href",
] as $marker) {
    $contains($actionBar, $marker, 'Canonical ActionBar must preserve generic attributes on link actions.');
}

$dataTable = $read('app/Interfaces/Web/View/components/ui/data_table.phtml');
foreach ([
    '($value[\'kind\'] ?? \'\') === \'actions\'',
    "partial('components/ui/action_bar'",
    '\'actions\' => is_array($value[\'items\']',
] as $marker) {
    $contains($dataTable, $marker, 'Canonical DataTable must support reusable action cells.');
}

$docs = $read('docs/03-architecture/cos-production-property-adoption.md');
foreach ([
    '# Впровадження Property у production UI',
    '## Хвиля 1',
    '### Реєстр менеджера (`Manager Registry`)',
    '### Sales Inventory',
    '### Порівняння (`Compare`)',
    '## Хвиля 2',
    '### Робочий простір групи (`Group Workspace`)',
    '## Хвиля 3',
    '### Створення об’єкта (`Property Add`)',
    '### Редактор об’єкта (`Property Edit`)',
    '## Межа editable grid',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Property production adoption documentation is incomplete.');
}

echo "PHASE 10 Property production adoption passed.\n";
