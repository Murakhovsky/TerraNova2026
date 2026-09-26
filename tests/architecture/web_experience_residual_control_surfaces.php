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

$cos = $read('symfony/templates/experience/operations/control_center.html.twig');
$cosPresenter = $read('symfony/src/Web/Operations/ControlCenterPresenter.php');
$cosController = $read('symfony/src/Web/Operations/ControlCenterPageController.php');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'id="{{ group.key }}"',
    'id="actions"',
    'id="approvals"',
    '/cos/action/',
    '/cos/approval/',
    'name="csrf_token"',
] as $marker) {
    $contains($cos, $marker, 'COS Control Center lost canonical read/mutation contract.');
}
foreach (['tn-', '<table', 'style=', '<script'] as $legacy) {
    $notContains($cos, $legacy, 'COS Control Center restored legacy/local presentation.');
}
foreach (["'events'","'rules'","'agents'","'policies'","'integrations'","'decisions'","'results'","'audit'"] as $marker) {
    $contains($cosPresenter, $marker, 'COS presenter lost runtime projection: ' . $marker);
}
foreach ([
    'OperationsMutationCommand::EXECUTE_ACTION',
    'OperationsMutationCommand::APPROVE',
    'OperationsMutationCommand::REJECT',
    'SessionCsrfValidator',
] as $marker) {
    $contains($cosController, $marker, 'COS controller lost governed mutation behavior: ' . $marker);
}
if (is_file($root . '/app/Interfaces/Web/View/cos/index.phtml')) {
    throw new RuntimeException('Retired COS Control Center PHTML restored.');
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

$users = $read('symfony/templates/experience/admin/users.html.twig');
$userItem = $read('symfony/templates/components/identity/user_administration_item.html.twig');
foreach ([
    '<twig:CosToolbar',
    '<twig:CosFilterBar',
    '<twig:CosDataGrid',
    '<twig:IdentityUserAdministrationItem',
    '/admin/createUser',
] as $marker) {
    $contains($users, $marker, 'Users System Control Surface lost canonical composition.');
}
foreach ([
    '/admin/updateUser/',
    'name="csrf_token"',
    'name="full_name"',
    'name="phone"',
    'name="role"',
    'name="status"',
    'name="password"',
    'type="submit"',
] as $marker) {
    $contains($userItem, $marker, 'Identity user domain component lost mutation parity.');
}
foreach (['<table', 'tn-', 'style=', '<script'] as $forbidden) {
    $notContains($users, $forbidden, 'Users page must not restore residual raw/legacy presentation.');
    $notContains($userItem, $forbidden, 'Identity user component must not restore residual raw/legacy presentation.');
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

$studio = $read('app/Interfaces/Web/View/methodology_studio/index.phtml');
foreach ([
    'class="entity-grid"',
    'class="entity-grid__head"',
    'class="entity-grid__body"',
    'data-entities',
    'data-editor',
    'data-action="add"',
    'data-action="publish"',
] as $marker) {
    $contains($studio, $marker, 'Methodology Studio entity browser must retain its interactive editor surface.');
}
$notContains($studio, '<table', 'Methodology Studio must not retain a raw table after entity-grid migration.');

$studioJs = $read('frontend/features/diagnostics/methodology-studio.js');
foreach ([
    'entity-grid__row',
    'entity-grid__identity',
    'data-edit=',
    "q('[data-entities]').onclick",
] as $marker) {
    $contains($studioJs, $marker, 'Methodology Studio JS must preserve entity-grid rendering and edit delegation.');
}
$notContains($studioJs, '<tr>', 'Methodology Studio JS must not restore table-row rendering.');

$studioCss = $read('frontend/features/diagnostics/methodology-studio.css');
foreach ([
    '.entity-grid',
    '.entity-grid__head',
    '.entity-grid__row',
    '.entity-grid__identity',
    '.entity-grid__empty',
] as $marker) {
    $contains($studioCss, $marker, 'Methodology Studio entity-grid styling is incomplete.');
}
$notContains($studioCss, '.studio table', 'Methodology Studio must not restore table-specific styling.');

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
