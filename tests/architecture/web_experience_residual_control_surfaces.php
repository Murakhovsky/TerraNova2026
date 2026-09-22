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

$docs = $read('docs/03-architecture/cos-residual-control-surface-closure.md');
foreach ([
    '# Закриття залишкових control surfaces',
    '## Хвиля 1',
    '### Client Case inbound relations',
    '## Винятки',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 13 documentation is incomplete.');
}

echo "PHASE 13 residual control surface closure passed.\n";
