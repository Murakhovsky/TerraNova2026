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
    '($value[\'kind\'] ?? \'\') === \'details\'',
    'tn-ui-data-table__details',
    'summary><?php echo $h($value[\'summary\'] ?? \'Details\');',
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
    "'bodyPartial' => 'components/ui/data_table'",
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
$notContains($cos, '<table class="tn-listing-table">', 'COS Control Center must not retain the retired raw Proposed Actions table.');
foreach ([
    '$actionRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_actions' => \$rowActions",
    "'kind' => 'form'",
    "'csrf_token' => \$csrfToken",
] as $marker) {
    $contains($cos, $marker, 'COS Proposed Actions must use canonical OperationalGrid: ' . $marker);
}

$companyHome = $read('app/Interfaces/Web/View/admin/index.phtml');
foreach ([
    '$decisionRows = [];',
    "partial('components/ui/data_table'",
    "'responsive' => 'cards'",
    "'emptyMessage' => 'Немає рішень, що очікують уваги.'",
] as $marker) {
    $contains($companyHome, $marker, 'Company Home decision queue must use canonical DataTable.');
}
$notContains($companyHome, '<table', 'Company Home must not retain a raw read-only table.');

$canonicalTableRenderers = [
    'app/Interfaces/Web/View/components/ui/data_table.phtml' => 'canonical read-only DataTable renderer',
    'app/Interfaces/Web/View/components/ui/operational_grid.phtml' => 'canonical mutation-aware OperationalGrid renderer',
];
$allowedTableViews = [
    'app/Interfaces/Web/View/methodology_studio/index.phtml' => 'interactive Methodology Studio editor grid',
    'app/Interfaces/Web/View/property/pdf.phtml' => 'service-level print renderer',
];
$classifiedTableViews = $canonicalTableRenderers + $allowedTableViews;

$viewRoot = $root . '/app/Interfaces/Web/View';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS));
$seenTableViews = [];
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'phtml') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false || !str_contains($source, '<table')) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $seenTableViews[$relative] = true;
    if (!isset($classifiedTableViews[$relative])) {
        throw new RuntimeException('Unclassified raw table surface found: ' . $relative);
    }
}
foreach ($classifiedTableViews as $relative => $reason) {
    if (!isset($seenTableViews[$relative])) {
        throw new RuntimeException('Declared table exception no longer contains a table; update the whitelist: ' . $relative . ' (' . $reason . ')');
    }
}

$users = $read('app/Interfaces/Web/View/admin/users.phtml');
foreach ([
    '$userRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_form' => [",
    "'action' => 'admin/updateUser/'",
    "'kind' => 'submit'",
    "'name' => 'full_name'",
    "'name' => 'phone'",
    "'name' => 'role'",
    "'name' => 'status'",
    "'name' => 'password'",
] as $marker) {
    $contains($users, $marker, 'Users must use canonical OperationalGrid editable row forms.');
}
$notContains($users, '<table', 'Users Administration must not retain a raw table after OperationalGrid migration.');

$clientIndex = $read('app/Interfaces/Web/View/client_case/index.phtml');
foreach ([
    '$caseRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_form' => [",
    "'action' => 'client-case/quickUpdate/'",
    "'csrf_token' => (string) (\$csrfToken ?? '')",
    "'return_url' => 'client-case'",
    "'kind' => 'stage'",
    "'kind' => 'fields'",
    "'kind' => 'submit'",
    "'name' => 'stage_id'",
    "'name' => 'status'",
    "'name' => 'priority'",
    "'name' => 'assigned_user_id'",
] as $marker) {
    $contains($clientIndex, $marker, 'Client Case quick-update list must use canonical OperationalGrid.');
}
$notContains($clientIndex, '<table', 'Client Case index must not retain a raw table after OperationalGrid migration.');

$studio = $read('app/Interfaces/Web/View/methodology_studio/index.phtml');
foreach (['data-entities', 'data-editor', 'data-action="add"', 'data-action="publish"'] as $marker) {
    $contains($studio, $marker, 'Methodology Studio raw table exception must remain an interactive editor surface.');
}

$pdf = $read('app/Interfaces/Web/View/property/pdf.phtml');
foreach (['<style>', 'page-break-inside', 'documentType', 'group-card'] as $marker) {
    $contains($pdf, $marker, 'Property PDF table exception must remain a print-layout renderer.');
}
$pdfService = $read('app/Domains/Property/Infrastructure/Presentation/PropertyPresentationService.php');
$contains($pdfService, 'property/pdf.phtml', 'Property PDF exception must remain owned by PropertyPresentationService.');

$docs = $read('docs/03-architecture/cos-residual-control-surface-closure.md');
foreach ([
    '# Закриття залишкових control surfaces',
    '## Хвиля 1',
    '### Вхідні зв’язки Client Case',
    '## Хвиля 2',
    '### Таблиці COS Control Center',
    '## Хвиля 3',
    '### Фінальний аудит таблиць',
    '### Черга рішень Company Home',
    '## Винятки',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 13 documentation is incomplete.');
}

echo "PHASE 13 residual control surface closure passed.\n";
