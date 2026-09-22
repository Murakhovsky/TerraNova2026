<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 13 artifact: ' . $path);
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

$show = $read('app/Interfaces/Web/View/client_case/show.phtml');
foreach ([
    '$inboundRows = [];',
    "partial('components/ui/data_table'",
    "'responsive' => 'cards'",
    "'emptyMessage' => 'До кейсу ще не привʼязано заявок.'",
    "'property' => [",
    "'_href' => \$propertySlug !== '' ? 'property/show/' . \$propertySlug : ''",
] as $marker) {
    $contains($show, $marker, 'Client Case inbound relations must use canonical DataTable and preserve property deep links.');
}
$notContains($show, '<table class="tn-listing-table">', 'Client Case show must not restore the residual raw inbound table.');

$clientCaseGate = $read('tests/architecture/web_experience_production_client_case.php');
foreach ([
    "partial('components/ui/entity_header'",
    'tn-match-form',
    'property/presentationShare',
] as $marker) {
    $contains($clientCaseGate, $marker, 'PHASE 12 Client Case workflow guard must remain intact.');
}

$dataTable = $read('app/Interfaces/Web/View/components/ui/data_table.phtml');
foreach ([
    "($value['kind'] ?? '') === 'details'",
    'tn-ui-data-table__details',
    "summary><?php echo $h($value['summary'] ?? 'Details');",
] as $marker) {
    $contains($dataTable, $marker, 'Canonical DataTable must support safe details cells for runtime JSON/config output.');
}

$cos = $read('app/Interfaces/Web/View/cos/index.phtml');
foreach ([
    '$eventRows = [];',
    '$ruleRows = [];',
    '$agentRows = [];',
    '$policyRows = [];',
    '$integrationRows = [];',
    '$resultRows = [];',
    "partial('components/ui/data_table'",
    "'kind' => 'details'",
    'id="events"',
    'id="rules"',
    'id="agents"',
    'id="policies"',
    'id="integrations"',
    'id="results"',
    'id="actions"',
    'cos/action/',
    'cos/approval/',
    'name="csrf_token"',
] as $marker) {
    $contains($cos, $marker, 'COS Control Center lost a canonical read-only table or operational action contract.');
}
if (substr_count($cos, '<table class="tn-listing-table">') !== 1) {
    throw new RuntimeException('COS Control Center must keep exactly one raw table: the operational Proposed Actions grid.');
}
$contains($cos, '<section class="tn-ui-panel tn-ui-panel--flush tn-cos-section tn-workspace-section" id="actions">', 'Operational Proposed Actions surface must remain explicit.');

$docs = $read('docs/03-architecture/cos-residual-control-surface-closure.md');
foreach ([
    '# Закриття залишкових control surfaces',
    '## Хвиля 1',
    '### Вхідні зв’язки Client Case',
    '## Хвиля 2',
    '### Таблиці COS Control Center',
    '## Винятки',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 13 documentation is incomplete.');
}

echo "PHASE 13 residual control surface closure passed.\n";
