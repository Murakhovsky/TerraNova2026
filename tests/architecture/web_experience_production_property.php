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

$dataTable = $read('app/Interfaces/Web/View/components/ui/data_table.phtml');
foreach ([
    "($value['kind'] ?? '') === 'actions'",
    "partial('components/ui/action_bar'",
    "'actions' => is_array($value['items']",
] as $marker) {
    $contains($dataTable, $marker, 'Canonical DataTable must support reusable action cells.');
}

$docs = $read('docs/03-architecture/cos-production-property-adoption.md');
foreach ([
    '# COS Production Property Adoption',
    '## Хвиля 1',
    '### Manager Registry',
    '### Sales Inventory',
    '### Compare',
    '## Editable grid boundary',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Property production adoption documentation is incomplete.');
}

echo "PHASE 10 Property production adoption passed.\n";
