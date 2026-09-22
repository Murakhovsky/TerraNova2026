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
    'tn-client-case-operational-grid',
    'tn-quick-case-form',
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
    "'active' => $activeTab",
    'client-case/create',
    'client-case/createFromInboundRequest/',
    'client-case/linkInboundRequest',
    'client-case/quickUpdate/',
    'client-case/show/',
    'name="csrf_token"',
    'name="stage_id"',
    'name="status"',
    'name="priority"',
    'name="assigned_user_id"',
    'name="return_url"',
] as $marker) {
    $contains($index, $marker, 'Client Case index lost a funnel/create/quick-update contract.');
}

$controller = $read('symfony/src/Web/Sales/ClientCasePageController.php');
foreach ([
    'public function index(Request $request): Response',
    'public function inbox(Request $request): Response',
    'public function create(Request $r): Response',
    'public function quickUpdate(Request $r,string $id): Response',
    'public function updateInboundRequest(Request $r,string $id): Response',
    'public function createFromInboundRequest(Request $r,string $id): Response',
    'public function linkInboundRequest(Request $r): Response',
    'private function mutationTenant(Request $r): TenantContext|Response',
    '$this->csrf->isValid($r)',
    '$this->write($t)->createOpportunity',
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
    '## Межа operational cards',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Client Case production adoption documentation is incomplete.');
}

echo "PHASE 12 Client Case production adoption passed.\n";
