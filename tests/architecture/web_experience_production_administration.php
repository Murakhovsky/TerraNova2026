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

$docs = $read('docs/03-architecture/cos-production-administration-adoption.md');
foreach ([
    '# Впровадження Administration у production UI',
    '## Хвиля 1',
    '### Користувачі та ролі (`Users Administration`)',
    '## Межа editable grid',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Administration production adoption documentation is incomplete.');
}

echo "PHASE 11 Administration production adoption passed.\n";
