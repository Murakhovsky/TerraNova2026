<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$adminController = file_get_contents($root . '/app/Interfaces/Web/Controller/AdminController.php');
$analyticsView = file_get_contents($root . '/app/Interfaces/Web/View/admin/analytics.phtml');
$entrypoint = file_get_contents($root . '/frontend/entrypoints/analytics-workspace.js');
$browserModule = file_get_contents($root . '/frontend/features/analytics/workspace.js');
$styles = file_get_contents($root . '/frontend/features/analytics/workspace.css');
$vite = file_get_contents($root . '/vite.config.js');
$frontendAssets = file_get_contents($root . '/tests/architecture/frontend_assets.php');
$diagnosticWorkflow = file_get_contents($root . '/.github/workflows/diagnostic.yml');

$requireContains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$requireNotContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$requireContains($adminController, "\$this->view->workspaceSection = 'analytics';", 'Analytics action must declare the Analytics workspace section.');
$requireContains($adminController, "\$this->view->workspaceActive = 'analytics';", 'Analytics action must declare the Analytics active navigation key.');
$requireContains($adminController, "\$this->view->pageAssetEntries = ['analytics-workspace'];", 'Analytics action must load the dedicated Vite bundle.');
$requireContains($adminController, "\$this->view->metaRobots = 'noindex,nofollow';", 'Analytics workspace must remain private for search engines.');
$requireContains($analyticsView, "partial('shared/manager_header'", 'Historical analytics header call is expected and must stay covered by the shared shell compatibility guard.');
$requireNotContains($analyticsView, '/assets/js/', 'Analytics view must not bypass Vite with direct JS assets.');
$requireNotContains($analyticsView, '/assets/css/', 'Analytics view must not bypass Vite with direct CSS assets.');
$requireContains($entrypoint, "../features/analytics/workspace.css", 'Analytics entrypoint must import feature CSS.');
$requireContains($entrypoint, "../features/analytics/workspace.js", 'Analytics entrypoint must import feature JS.');
$requireContains($browserModule, 'dataset.analyticsWorkspace', 'Analytics browser module must expose its migrated workspace state.');
$requireContains($browserModule, 'aria-busy', 'Analytics browser module must expose progressive submit state.');
$requireContains($styles, '.tn-analytics-workspace', 'Analytics feature stylesheet must be workspace-scoped.');
$requireContains($styles, '@media (max-width: 650px)', 'Analytics feature stylesheet must cover the mobile baseline.');
$requireContains($vite, "'analytics-workspace'", 'Vite must expose the analytics workspace entrypoint.');
$requireContains($frontendAssets, "'analytics-workspace'", 'Frontend asset validation must include the analytics workspace entrypoint.');
$requireNotContains($diagnosticWorkflow, 'tests/unit/sales_v063.php', 'Runtime workflow must not invoke the deleted Sales V0.6.3 unit test.');

echo "WEB V0.8 analytics workspace architecture passed.\n";
