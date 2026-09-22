<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing OperationalGrid artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};

$grid = $read('app/Interfaces/Web/View/components/ui/operational_grid.phtml');
$cos = $read('app/Interfaces/Web/View/cos/index.phtml');
$css = $read('frontend/styles/canonical-components.css');

foreach ([
    'tn-ui-operational-grid',
    'tn-ui-data-table',
    '$rowActions',
    '$rowForm',
    "'kind'] ?? 'link'",
    "'hidden'] ?? null",
    "'fields'] ?? null",
    "'type'] ?? 'select'",
    'method="post"',
    'status_badge',
] as $marker) {
    if (!str_contains($grid, $marker)) {
        throw new RuntimeException('OperationalGrid contract incomplete: ' . $marker);
    }
}

foreach ([
    '$actionRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'kind' => 'form'",
    "'kind' => 'link'",
    "'csrf_token' => $csrfToken",
    'cos/action/',
    '#approval-',
] as $marker) {
    if (!str_contains($cos, $marker)) {
        throw new RuntimeException('COS Proposed Actions migration incomplete: ' . $marker);
    }
}

if (str_contains($cos, '<table class="tn-listing-table">')) {
    throw new RuntimeException('COS Proposed Actions raw table must remain retired.');
}

foreach ([
    '.tn-ui-operational-grid__actions',
    '.tn-ui-operational-grid__form',
    '.tn-ui-operational-grid__actions-heading',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('OperationalGrid CSS contract incomplete: ' . $marker);
    }
}

$clientCase = $read('app/Interfaces/Web/View/client_case/index.phtml');
foreach ([
    '$caseRows = [];',
    "partial('components/ui/operational_grid'",
    "'_form' => [",
    "'action' => 'client-case/quickUpdate/'",
] as $marker) {
    if (!str_contains($clientCase, $marker)) {
        throw new RuntimeException('Client Case OperationalGrid adoption incomplete: ' . $marker);
    }
}
if (str_contains($clientCase, '<table')) {
    throw new RuntimeException('Client Case index raw operational table must remain retired.');
}

echo "OperationalGrid canonical mutation surface passed.\n";
