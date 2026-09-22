<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 11 Administration artifact: ' . $path);
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

$users = $read('app/Interfaces/Web/View/admin/users.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/filter_bar'",
    "partial('components/ui/panel'",
    "'bodyPartial' => 'components/ui/data_table'",
    'tn-admin-editable-grid',
] as $marker) {
    $contains($users, $marker, 'Users Administration must use canonical workspace composition.');
}
foreach ([
    'tn-listing-hero',
    '<section class="tn-admin-metrics"',
    '<form class="tn-crm-form" action="<?php echo $this->url->get(\'admin/users\'); ?>" method="get">',
] as $legacyMarker) {
    $notContains($users, $legacyMarker, 'Users Administration must not restore legacy shell/filter composition.');
}

foreach ([
    'admin/createUser',
    'admin/updateUser/',
    'name="csrf_token"',
    'name="full_name"',
    'name="email"',
    'name="phone"',
    'name="password"',
    'name="role"',
    'name="status"',
    'user-form-',
] as $marker) {
    $contains($users, $marker, 'Users Administration lost a create/update mutation contract.');
}

$controller = $read('symfony/src/Web/Workspace/CoreWorkspacePageController.php');
foreach ([
    'public function users(Request $request): Response',
    'public function createUser(Request $request): Response',
    'public function updateUser(Request $request, string $id): Response',
    '$this->admin()',
    '$this->csrf->isValid($request)',
    '$this->administration->createUser',
    '$this->administration->updateUser',
    "'admin/users'",
] as $marker) {
    $contains($controller, $marker, 'Users Administration controller contract is incomplete.');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /admin/users',
    'CoreWorkspacePageController::users',
    'path: /admin/createUser',
    'CoreWorkspacePageController::createUser',
    'path: /admin/updateUser/{id}',
    'CoreWorkspacePageController::updateUser',
] as $marker) {
    $contains($routes, $marker, 'Users Administration route contract is incomplete.');
}

$contentManage = $read('app/Interfaces/Web/View/content/manage.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/filter_bar'",
    "partial('components/ui/panel'",
    "'bodyPartial' => 'components/ui/data_table'",
    'admin/content/edit/',
    'id="n8n"',
] as $marker) {
    $contains($contentManage, $marker, 'Content Administration listing must use canonical workspace composition.');
}
foreach ([
    'tn-page-hero tn-page-hero--catalog',
    '<section class="tn-admin-metrics"',
    '<form class="tn-filter-bar"',
    '<table class="tn-listing-table"',
] as $legacyMarker) {
    $notContains($contentManage, $legacyMarker, 'Content Administration listing must not restore legacy shell/filter/table composition.');
}

$contentEdit = $read('app/Interfaces/Web/View/content/edit.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'admin/content/save/',
    'name="csrf_token"',
    'name="content_type"',
    'name="status"',
    'name="slug"',
    'name="body_html"',
    'name="meta_title"',
    'name="meta_description"',
    'name="focus_keyword"',
    'name="robots"',
    'name="canonical_url"',
    'name="og_image_url"',
    'name="schema_json"',
] as $marker) {
    $contains($contentEdit, $marker, 'Content editor lost a canonical or save/SEO contract.');
}
foreach ([
    'tn-page-hero tn-page-hero--catalog',
    'tn-admin-card',
    'tn-admin-card__head',
] as $legacyMarker) {
    $notContains($contentEdit, $legacyMarker, 'Content editor must not restore the legacy visual shell.');
}

$contentController = $read('symfony/src/Web/Content/ContentAdminPageController.php');
foreach ([
    'public function manage(Request $request): Response',
    'public function edit(Request $request, string $id = \'0\'): Response',
    'public function save(Request $request, string $id = \'0\'): Response',
    '$this->manager()',
    '$this->csrf->isValid($request)',
    '$this->content->save',
    "'content/manage'",
    "'content/edit'",
] as $marker) {
    $contains($contentController, $marker, 'Content Administration controller contract is incomplete.');
}

foreach ([
    'path: /admin/content',
    'ContentAdminPageController::manage',
    'path: /admin/content/edit',
    'ContentAdminPageController::edit',
    'path: /admin/content/save/{id}',
    'ContentAdminPageController::save',
] as $marker) {
    $contains($routes, $marker, 'Content Administration route contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-production-administration-adoption.md');
foreach ([
    '# Впровадження Administration у production UI',
    '## Хвиля 1',
    '### Користувачі та ролі (`Users Administration`)',
    '## Хвиля 2',
    '### Контент і SEO (`Content Administration`)',
    '### Редактор контенту (`Content Editor`)',
    '## Межа editable grid',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Administration production adoption documentation is incomplete.');
}

echo "PHASE 11 Administration production adoption passed.\n";
