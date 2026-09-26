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
    "'bodyPartial' => 'components/ui/operational_grid'",
    '$userRows = [];',
    "'_form' => [",
    "'kind' => 'submit'",
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
    "'name' => 'full_name'",
    "'name' => 'phone'",
    "'name' => 'role'",
    "'name' => 'status'",
    "'name' => 'password'",
] as $marker) {
    $contains($users, $marker, 'Users Administration lost a create/update mutation contract.');
}
$notContains($users, '<table', 'Users Administration must not retain a raw editable table.');

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

$spatialManage = $read('symfony/templates/experience/spatial/manage.html.twig');
foreach ([
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosToolbar',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    '<twig:CosContextPanel',
    'spatial/edit',
    'id="queue"',
] as $marker) {
    $contains($spatialManage, $marker, 'Spatial Administration listing must use canonical Map / Spatial composition.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $legacyMarker) {
    $notContains($spatialManage, $legacyMarker, 'Spatial Administration listing must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/spatial/manage.phtml')) {
    throw new RuntimeException('Legacy Spatial manage PHTML restored.');
}

$spatialEdit = $read('app/Interfaces/Web/View/spatial/edit.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'spatial/save/',
    'spatial/upload/',
    'spatial/external/',
    'spatial/capture/',
    'spatial/hotspot/',
    'spatial/publish/',
    'data-spatial-upload',
    'data-spatial-dropzone',
    'data-spatial-file',
    'data-spatial-progress',
    'data-spatial-upload-status',
    'enctype="multipart/form-data"',
    'name="asset_file"',
    'name="scene_type"',
    'name="viewer_type"',
    'name="provider"',
    'name="external_url"',
    'name="hotspot_type"',
] as $marker) {
    $contains($spatialEdit, $marker, 'Spatial editor lost a canonical or mutation/browser contract.');
}
foreach ([
    'tn-page-hero tn-page-hero--catalog',
    'tn-admin-card',
    'tn-admin-card__head',
] as $legacyMarker) {
    $notContains($spatialEdit, $legacyMarker, 'Spatial editor must not restore the legacy visual shell.');
}

$spatialManageController = $read('symfony/src/Web/Spatial/SpatialManageController.php');
foreach (['GetSpatialManageQuery', 'PageArchetype::MapSpatial', 'WorkspaceShellFactory'] as $marker) {
    $contains($spatialManageController, $marker, 'Spatial Manage controller contract is incomplete.');
}

$spatialController = $read('symfony/src/Web/Spatial/SpatialPageController.php');
foreach ([
    'public function edit(Request $request, ?string $id = null): Response',
    'public function save(Request $request, ?string $id = null): Response',
    'public function upload(Request $request, string $id): Response',
    'public function external(Request $request, string $id): Response',
    'public function capture(Request $request, string $id): Response',
    'public function hotspot(Request $request, string $id): Response',
    'public function publish(string $id): Response',
    'public function scene(Request $request, string $slug): Response',
    '$this->manager()',
    "'spatial/edit'",
    "'spatial/scene'",
] as $marker) {
    $contains($spatialController, $marker, 'Spatial Administration controller contract is incomplete.');
}

foreach ([
    'path: /spatial/manage',
    'SpatialManageController::index',
    'path: /spatial/edit',
    'SpatialPageController::edit',
    'path: /spatial/save',
    'SpatialPageController::save',
    'path: /spatial/upload/{id}',
    'SpatialPageController::upload',
    'path: /spatial/external/{id}',
    'SpatialPageController::external',
    'path: /spatial/capture/{id}',
    'SpatialPageController::capture',
    'path: /spatial/hotspot/{id}',
    'SpatialPageController::hotspot',
    'path: /spatial/publish/{id}',
    'SpatialPageController::publish',
    'path: /spatial/scene/{slug}',
    'SpatialPageController::scene',
] as $marker) {
    $contains($routes, $marker, 'Spatial Administration route contract is incomplete.');
}

$publicScene = $read('app/Interfaces/Web/View/spatial/scene.phtml');
foreach ([
    "partial('shared/spatial_viewer'",
    'tn-spatial-summary',
] as $marker) {
    $contains($publicScene, $marker, 'Public Spatial scene specialized surface must remain intact.');
}

$companyHome = $read('symfony/templates/experience/admin/dashboard.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosMetric',
    '<twig:CosNextAction',
    '<twig:CosEntityListItem',
    'data-cos-archetype',
    'dashboard.decisions',
] as $marker) {
    $contains($companyHome, $marker, 'Company Home must use the Wave 13 Executive Dashboard composition.');
}
foreach (['tn-', 'style=', '<script', 'PhtmlRenderer'] as $legacyMarker) {
    $notContains($companyHome, $legacyMarker, 'Company Home must not retain legacy/local visual composition.');
}

$analytics = $read('symfony/templates/experience/administration/analytics.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    'class="cos-kpi-strip"',
    '<twig:CosMetric',
    '<twig:CosDataGrid',
    '<twig:CosEntityListItem',
    'data-cos-archetype',
] as $marker) {
    $contains($analytics, $marker, 'Administration Analytics canonical Executive Dashboard is incomplete.');
}
foreach (['tn-', 'style=', '<script', '<table'] as $legacyMarker) {
    $notContains($analytics, $legacyMarker, 'Administration Analytics must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/admin/analytics.phtml')) {
    throw new RuntimeException('Legacy Administration Analytics PHTML restored.');
}

$analyticsController = $read('symfony/src/Web/Administration/AdministrationAnalyticsController.php');
foreach (['GetAdministrationAnalyticsQuery', 'PageArchetype::ExecutiveDashboard', 'WorkspaceShellFactory', 'AdministrationAnalyticsPresenter'] as $marker) {
    $contains($analyticsController, $marker, 'Administration Analytics controller contract is incomplete.');
}

foreach ([
    'path: /admin',
    'ExecutiveDashboardController::index',
    'path: /admin/analytics',
    'AdministrationAnalyticsController::index',
] as $marker) {
    $contains($routes, $marker, 'Administration closure route contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-production-administration-adoption.md');
foreach ([
    '# Впровадження Administration у production UI',
    '## Хвиля 1',
    '### Користувачі та ролі (`Users Administration`)',
    '## Хвиля 2',
    '### Контент і SEO (`Content Administration`)',
    '### Редактор контенту (`Content Editor`)',
    '## Хвиля 3',
    '### Керування Spatial (`Spatial Administration`)',
    '### Редактор Spatial (`Spatial Editor`)',
    '## Хвиля 4',
    '### Закриття Administration (`Administration Closure`)',
    '## Межа editable grid',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Administration production adoption documentation is incomplete.');
}

echo "PHASE 11 Administration production adoption passed.\n";
