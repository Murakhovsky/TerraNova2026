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

$show = $read('symfony/templates/experience/client_case/show.html.twig');
foreach ([
    '<twig:CosWorkspace',
    '<twig:CosEntityHeader',
    'request.propertyHref',
    'match.pdfHref',
    '/property/presentationShare',
    '/client-case/updatePropertyMatch/',
] as $marker) {
    $contains($show, $marker, 'Client Case Workspace must preserve relation/matching contracts.');
}
foreach (['<table', 'tn-', 'style=', '<script'] as $forbidden) {
    $notContains($show, $forbidden, 'Client Case Workspace must not restore residual raw/legacy presentation.');
}

$clientCasePresenter=$read('symfony/src/Web/Sales/ClientCaseWorkspacePresenter.php');
foreach(["'/property/show/'","'/property/pdf/'"] as $marker){
    $contains($clientCasePresenter,$marker,'Client Case presenter must preserve property deep links.');
}

$clientCaseGate = $read('tests/architecture/web_experience_production_client_case.php');
foreach ([
    '<twig:CosWorkspace',
    '/client-case/updatePropertyMatch/',
    '/property/presentationShare',
] as $marker) {
    $contains($clientCaseGate, $marker, 'Client Case production workflow guard must remain intact.');
}

$dataTable = $read('app/Interfaces/Web/View/components/ui/data_table.phtml');
foreach ([
    '($value[\'kind\'] ?? \'\') === \'details\'',
    'tn-ui-data-table__details',
    'summary><?php echo $h($value[\'summary\'] ?? \'Details\');',
] as $marker) {
    $contains($dataTable, $marker, 'Canonical DataTable must support safe details cells for runtime JSON/config output.');
}

$cos = $read('symfony/templates/experience/system/control_center.html.twig');
$cosPresenter = $read('symfony/src/Web/Operations/ControlCenterPresenter.php');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'id="{{ section.id }}"',
    'item.executeUrl',
    'item.approveUrl',
    'name="csrf_token"',
] as $marker) {
    $contains($cos, $marker, 'COS Control Center lost canonical System Control Surface composition.');
}
foreach (['events', 'rules', 'agents', 'policies', 'integrations', 'decisions', 'actions', 'approvals', 'results', 'audit'] as $section) {
    $contains($cosPresenter, "'".$section."'", 'COS Control Center presenter lost section: ' . $section);
}
foreach (['tn-', '<table', 'style=', '<script'] as $forbidden) {
    $notContains($cos, $forbidden, 'COS Control Center must not restore legacy/local presentation.');
}

$companyHome = $read('symfony/templates/experience/admin/dashboard.html.twig');
foreach ([
    'dashboard.decisions',
    '<twig:CosEntityListItem',
    'Що чекає рішення',
] as $marker) {
    $contains($companyHome, $marker, 'Company Home decision queue must remain on canonical reusable patterns.');
}
$notContains($companyHome, '<table', 'Company Home must not retain a raw read-only table.');
$notContains($companyHome, 'tn-', 'Company Home must not restore legacy TN presentation.');

$canonicalTableRenderers = [
    'app/Interfaces/Web/View/components/ui/data_table.phtml' => 'canonical read-only DataTable renderer',
    'app/Interfaces/Web/View/components/ui/operational_grid.phtml' => 'canonical mutation-aware OperationalGrid renderer',
];
$allowedTableViews = [];
$classifiedTableViews = $canonicalTableRenderers;

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

$users = $read('symfony/templates/experience/administration/users.html.twig');
foreach ([
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'action="/admin/updateUser/{{ user.id }}"',
    'name="csrf_token"',
    'name="full_name"',
    'name="phone"',
    'name="role"',
    'name="status"',
    'name="password"',
] as $marker) {
    $contains($users, $marker, 'Users must use canonical editable EntityList forms.');
}
foreach (['<table', 'tn-', 'style=', '<script'] as $forbidden) {
    $notContains($users, $forbidden, 'Users Administration must not restore legacy/local presentation.');
}

$clientCaseItem = $read('symfony/templates/components/client_case/client_case_collection_item.html.twig');
foreach ([
    '/client-case/quickUpdate/',
    'name="csrf_token"',
    'name="return_url"',
    'value="client-case"',
    'name="stage_id"',
    'name="status"',
    'name="priority"',
    'name="assigned_user_id"',
] as $marker) {
    $contains($clientCaseItem, $marker, 'Client Case domain Collection item lost quick-update mutation parity.');
}
$notContains($clientCaseItem, '<table', 'Client Case Collection item must not restore a raw table.');
$notContains($clientCaseItem, 'tn-', 'Client Case Collection item must not restore legacy TN presentation.');

$studio = $read('symfony/templates/experience/system/methodology_studio.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'data-controller="diagnostic-methodology"',
    'data-studio',
    'class="entity-grid"',
    'data-entities',
    'data-editor',
    'data-studio-action="add"',
    'data-studio-action="publish"',
] as $marker) {
    $contains($studio, $marker, 'Methodology Studio canonical editor surface is incomplete.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    $notContains($studio, $forbidden, 'Methodology Studio must not restore legacy/local presentation.');
}

$studioJs = $read('symfony/assets/islands/diagnostic_methodology/base.js');
foreach ([
    'entity-grid__row',
    'entity-grid__identity',
    'data-edit=',
    "q('[data-entities]').onclick",
    'data-studio-action',
] as $marker) {
    $contains($studioJs, $marker, 'Methodology Studio island must preserve entity-grid rendering and edit delegation.');
}
$notContains($studioJs, '<tr>', 'Methodology Studio island must not restore table-row rendering.');

$studioCss = $read('symfony/assets/styles/domains/diagnostic-methodology.css');
foreach ([
    '.cos-methodology-studio .entity-grid',
    '.cos-methodology-studio .entity-grid__head',
    '.cos-methodology-studio .entity-grid__row',
    '.cos-methodology-studio .entity-grid__identity',
    '.cos-methodology-studio .entity-grid__empty',
    '@media (max-width: 1050px)',
    '@media (max-width: 650px)',
] as $marker) {
    $contains($studioCss, $marker, 'Methodology Studio domain styling is incomplete.');
}
foreach (['var(--tn-', '#17202a', '#176b4d'] as $forbidden) {
    $notContains($studioCss, $forbidden, 'Methodology Studio CSS bypasses canonical COS tokens.');
}

$pdf = $read('app/Interfaces/Web/View/property/pdf.phtml');
foreach ([
    '<style>',
    'page-break-inside',
    'documentType',
    'group-card',
    'class="hero"',
    'class="facts"',
    'class="feature-table"',
    'class="gallery"',
    'class="partner-row"',
] as $marker) {
    $contains($pdf, $marker, 'Property PDF print-layout contract is incomplete.');
}
$notContains($pdf, '<table', 'Property PDF must not restore raw table layout.');
$notContains($pdf, '<tr', 'Property PDF must not restore table-row layout.');
$notContains($pdf, '<td', 'Property PDF must not restore table-cell layout.');
$pdfService = $read('app/Domains/Property/Infrastructure/Presentation/PropertyPresentationService.php');
$contains($pdfService, 'property/pdf.phtml', 'Property PDF renderer must remain owned by PropertyPresentationService.');

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
