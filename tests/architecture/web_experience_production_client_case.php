<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 12 Client Case artifact: ' . $path);
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

$inbox = $read('app/Interfaces/Web/View/client_case/inbox.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/tabs'",
    "partial('components/ui/filter_bar'",
    'tn-ui-panel tn-workspace-section',
    'tn-inbox-list',
    'tn-inbox-card',
    'tn-inbound-workflow-form',
] as $marker) {
    $contains($inbox, $marker, 'Client Case inbox must use canonical shell while retaining triage cards.');
}
foreach ([
    'tn-listing-hero',
    '<section class="tn-admin-metrics"',
    '<section class="tn-admin-tabs"',
    '<form class="tn-crm-filters"',
    '<div class="tn-empty-state">',
] as $legacyMarker) {
    $notContains($inbox, $legacyMarker, 'Client Case inbox must not restore legacy shell/filter/empty-state primitives.');
}

foreach ([
    'client-case/updateInboundRequest/',
    'client-case/createFromInboundRequest/',
    'client-case/linkInboundRequest',
    'client-case/show/',
    'property/show/',
    'name="csrf_token"',
    'name="return_url"',
    'name="status"',
    'name="assigned_user_id"',
    'name="next_contact_at"',
    'name="activity_type"',
    'name="manager_note"',
    'name="activity_body"',
    'name="priority"',
    'name="request_id"',
    'name="case_id"',
] as $marker) {
    $contains($inbox, $marker, 'Client Case inbox lost a triage mutation/navigation contract.');
}

$index = $read('app/Interfaces/Web/View/client_case/index.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/tabs'",
    "partial('components/ui/filter_bar'",
    'tn-ui-panel',
    'tn-case-funnel',
    '$caseRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_form' => [",
    "'kind' => 'fields'",
    "'kind' => 'stage'",
    "'kind' => 'submit'",
] as $marker) {
    $contains($index, $marker, 'Client Case index must use canonical shell while retaining funnel and operational mutations.');
}
foreach ([
    'tn-breadcrumbs',
    'tn-ui-alert tn-ui-alert--positive',
    'tn-ui-alert tn-ui-alert--danger',
] as $legacyMarker) {
    $notContains($index, $legacyMarker, 'Client Case index must not restore redundant breadcrumb/local alert composition.');
}
foreach ([
    "'active' => \$activeTab",
    'client-case/create',
    'client-case/createFromInboundRequest/',
    'client-case/linkInboundRequest',
    'client-case/quickUpdate/',
    'client-case/show/',
    "'csrf_token' => (string) (\$csrfToken ?? '')",
    "'return_url' => 'client-case'",
    "'name' => 'stage_id'",
    "'name' => 'status'",
    "'name' => 'priority'",
    "'name' => 'assigned_user_id'",
] as $marker) {
    $contains($index, $marker, 'Client Case index lost a funnel/create/quick-update contract.');
}
$notContains($index, '<table', 'Client Case index must not retain a raw operational table after OperationalGrid migration.');
$notContains($index, 'tn-client-case-operational-grid', 'Client Case index must not restore retired raw-grid marker.');
$notContains($index, 'tn-quick-case-form', 'Client Case index must not restore retired inline quick-update form.');

$show = $read('app/Interfaces/Web/View/client_case/show.phtml');
foreach ([
    "partial('components/ui/entity_header'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    'tn-workspace-page',
    'tn-ui-panel',
    'tn-ai-deal-card',
    'tn-crm-timeline',
    'tn-match-form',
] as $marker) {
    $contains($show, $marker, 'Client Case show must use canonical entity workspace while retaining specialized operational patterns.');
}
foreach ([
    'tn-listing-hero',
    'tn-breadcrumbs',
    'tn-admin-panel',
    'tn-section-heading',
    'tn-empty-state',
    'tn-btn tn-btn--dark',
] as $legacyMarker) {
    $notContains($show, $legacyMarker, 'Client Case show must not restore legacy entity shell primitives.');
}
foreach ([
    'client-case/update/',
    'client-case/activity/',
    'client-case/updatePropertyMatch/',
    'cos/action/',
    'cos/approval/',
    'property/presentationShare',
    'property/pdf/',
    'name="csrf_token"',
    'name="return_url"',
    'name="full_name"',
    'name="stage_id"',
    'name="assigned_user_id"',
    'name="next_contact_at"',
    'name="activity_type"',
    'name="match_status"',
    'name="score"',
    'name="note"',
] as $marker) {
    $contains($show, $marker, 'Client Case show lost an entity/workflow mutation contract.');
}

$clientEntrypoint = $read('frontend/entrypoints/clients-workspace.js');
$contains($clientEntrypoint, "../features/clients/workspace.css", 'Client Case entrypoint must retain domain CSS.');
$notContains($clientEntrypoint, "../features/clients/workspace.js", 'Client Case entrypoint must not restore retired scoping JS.');
if (is_file($root . '/frontend/features/clients/workspace.js')) {
    throw new RuntimeException('Retired Client Case workspace scoping script restored.');
}

$clientCss = $read('frontend/features/clients/workspace.css');
foreach ([
    '.tn-client-workspace',
    '.tn-inbox-card',
    '.tn-case-funnel',
    '.tn-ai-deal-card',
    '@media (max-width: 650px)',
] as $marker) {
    $contains($clientCss, $marker, 'Client Case domain CSS lost a live specialized pattern.');
}
foreach ([
    'Compatibility bridge while Client Case PHTML moves to canonical components.',
    'tn-listing-hero',
    'tn-admin-metrics',
    'tn-admin-tabs',
    'tn-admin-panel',
    'tn-empty-state',
    'tn-section-heading',
] as $legacyMarker) {
    $notContains($clientCss, $legacyMarker, 'Client Case compatibility CSS must remain retired.');
}

$webV017 = $read('docs/architecture/web-v0.17.md');
foreach ([
    'PHASE 12',
    '## Завершення WEB V0.17',
    'WEB V0.17 більше не має окремого compatibility-bridge боргу',
] as $marker) {
    $contains($webV017, $marker, 'WEB V0.17 closure documentation is incomplete.');
}

$controller = $read('symfony/src/Web/Sales/ClientCasePageController.php');
foreach ([
    'public function index(Request $request): Response',
    'public function show(Request $request,string $id): Response',
    'public function update(Request $r,string $id): Response',
    'public function activity(Request $r,string $id): Response',
    'public function updatePropertyMatch(Request $r,string $id): Response',
    'public function inbox(Request $request): Response',
    'public function create(Request $r): Response',
    'public function quickUpdate(Request $r,string $id): Response',
    'public function updateInboundRequest(Request $r,string $id): Response',
    'public function createFromInboundRequest(Request $r,string $id): Response',
    'public function linkInboundRequest(Request $r): Response',
    'private function mutationTenant(Request $r): TenantContext|Response',
    '$this->csrf->isValid($r)',
    '$this->write($t)->createOpportunity',
    '$this->write($t)->updateOpportunity',
    '$this->write($t)->addOpportunityActivity',
    '$this->write($t)->updateOpportunityPropertyMatch',
    '$this->write($t)->quickUpdateOpportunity',
    '$this->write($t)->updateLead',
    '$this->write($t)->convertLeadToOpportunity',
    '$this->write($t)->attachInboundRequest',
    "'client_case/inbox'",
] as $marker) {
    $contains($controller, $marker, 'Client Case controller inbox contract is incomplete.');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /client-case',
    'path: /client-case/show/{id}',
    'ClientCasePageController::show',
    'path: /client-case/update/{id}',
    'ClientCasePageController::update',
    'path: /client-case/activity/{id}',
    'ClientCasePageController::activity',
    'path: /client-case/updatePropertyMatch/{id}',
    'ClientCasePageController::updatePropertyMatch',
    'ClientCasePageController::index',
    'path: /client-case/create',
    'ClientCasePageController::create',
    'path: /client-case/quickUpdate/{id}',
    'ClientCasePageController::quickUpdate',
    'path: /client-case/inbox',
    'ClientCasePageController::inbox',
    'path: /client-case/updateInboundRequest/{id}',
    'ClientCasePageController::updateInboundRequest',
    'path: /client-case/createFromInboundRequest/{id}',
    'ClientCasePageController::createFromInboundRequest',
    'path: /client-case/linkInboundRequest',
    'ClientCasePageController::linkInboundRequest',
] as $marker) {
    $contains($routes, $marker, 'Client Case inbox route contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-production-client-case-adoption.md');
foreach ([
    '# Впровадження Client Case у production UI',
    '## Хвиля 1',
    '### Вхідні заявки (`Client Case Inbox`)',
    '## Хвиля 2',
    '### Клієнтські кейси (`Client Case Index`)',
    '## Хвиля 3',
    '### Робочий простір кейсу (`Client Case Workspace`)',
    '## Хвиля 4',
    '### Завершення WEB V0.17',
    '## Межа operational cards',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Client Case production adoption documentation is incomplete.');
}

echo "PHASE 12 Client Case production adoption passed.\n";
