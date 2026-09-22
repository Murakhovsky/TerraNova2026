<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing WEB V0.8 Analytics artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$requireContains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$requireNotContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$controller = $read('symfony/src/Web/Workspace/CoreWorkspacePageController.php');
$analyticsView = $read('app/Interfaces/Web/View/admin/analytics.phtml');
$entrypoint = $read('frontend/entrypoints/analytics-workspace.js');
$browserModule = $read('frontend/features/analytics/workspace.js');
$styles = $read('frontend/features/analytics/workspace.css');
$vite = $read('vite.config.js');
$frontendAssets = $read('tests/architecture/frontend_assets.php');
$diagnosticWorkflow = $read('.github/workflows/diagnostic.yml');
$routes = $read('symfony/config/routes.yaml');

foreach ([
    'public function analytics(Request $request): Response',
    '$tenant = $this->manager()',
    "$this->render($request, $tenant, 'Аналітика', 'analytics', 'analytics', 'admin/analytics'",
    "['analytics-workspace']",
    "'metaRobots' => 'noindex,nofollow'",
    "'workspaceSection' => $section",
    "'workspaceActive' => $active",
] as $needle) {
    $requireContains($controller, $needle, 'Canonical Analytics controller contract is incomplete.');
}
foreach ([
    'path: /admin/analytics',
    'CoreWorkspacePageController::analytics',
] as $needle) {
    $requireContains($routes, $needle, 'Canonical Analytics route contract is incomplete.');
}

foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/filter_bar'",
    "partial('components/ui/state'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/data_table'",
    'tn-analytics-workspace',
] as $needle) {
    $requireContains($analyticsView, $needle, 'Analytics view lost canonical workspace composition.');
}
foreach ([
    'tn-page-hero',
    'tn-admin-metrics',
    'tn-admin-card',
    'tn-listing-table',
    '/assets/js/',
    '/assets/css/',
] as $needle) {
    $requireNotContains($analyticsView, $needle, 'Analytics view restored a retired presentation/runtime primitive.');
}

$requireContains($entrypoint, "../features/analytics/workspace.css", 'Analytics entrypoint must import feature CSS.');
$requireContains($entrypoint, "../features/analytics/workspace.js", 'Analytics entrypoint must import feature JS.');
$requireContains($browserModule, 'dataset.analyticsWorkspace', 'Analytics browser module must expose its migrated workspace state.');
$requireContains($browserModule, 'aria-busy', 'Analytics browser module must expose progressive submit state.');
$requireContains($styles, '.tn-analytics-workspace', 'Analytics feature stylesheet must be workspace-scoped.');
$requireContains($styles, '@media (max-width: 650px)', 'Analytics feature stylesheet must cover the mobile baseline.');
$requireContains($vite, "'analytics-workspace'", 'Vite must expose the analytics workspace entrypoint.');
$requireContains($frontendAssets, "'analytics-workspace'", 'Frontend asset validation must include the analytics workspace entrypoint.');
$requireNotContains($diagnosticWorkflow, 'tests/unit/sales_v063.php', 'Runtime workflow must not invoke the deleted Sales V0.6.3 unit test.');

echo "WEB V0.8 analytics workspace canonical runtime passed.\n";
