<?php

declare(strict_types=1);

use Interfaces\Web\Navigation\FrontendNavigation;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (is_dir($root . '/app/Domains/Frontend')) throw new RuntimeException('Frontend is an Interface/Presentation layer and must not become a DDD Domain.');

$keys = static fn (array $items): array => array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $items);
$section = static function (array $items, string $key): ?array {
    foreach ($items as $item) if (($item['key'] ?? '') === $key) return $item;
    return null;
};

$public = FrontendNavigation::public();
$expectedPublic = ['home', 'catalog', 'services', 'partners', 'about', 'cos'];
if ($keys($public) !== $expectedPublic) throw new RuntimeException('Public primary navigation changed without an explicit interface architecture decision.');

$workspaceCore = FrontendNavigation::workspaceCore('manager');
$corePrimary = $workspaceCore['primary'] ?? [];
$expectedCorePrimary = ['home', 'cos', 'analytics', 'administration'];
if ($keys($corePrimary) !== $expectedCorePrimary) throw new RuntimeException('Workspace core navigation must remain module-neutral.');
$managerAdministration = $section($corePrimary, 'administration');
if ($keys($managerAdministration['children'] ?? []) !== ['content']) throw new RuntimeException('Manager Administration must expose Content without admin-only Users.');

$adminWorkspaceCore = FrontendNavigation::workspaceCore('admin');
$adminCorePrimary = $adminWorkspaceCore['primary'] ?? [];
if ($keys($adminCorePrimary) !== $expectedCorePrimary) throw new RuntimeException('Admin Workspace core must remain module-neutral.');
$adminAdministration = $section($adminCorePrimary, 'administration');
if ($keys($adminAdministration['children'] ?? []) !== ['users', 'content']) throw new RuntimeException('Admin Administration must expose Users and Content.');

$portalCore = FrontendNavigation::portalCore('realtor');
if ($keys($portalCore['primary'] ?? []) !== ['cabinet']) throw new RuntimeException('Portal core must not hardcode Domain navigation.');

foreach ([
    'app/Interfaces/Web/Navigation/ModuleNavigationContributorInterface.php',
    'app/Interfaces/Web/Navigation/ModuleAwareNavigationService.php',
    'app/Interfaces/Web/Navigation/SalesNavigationContributor.php',
    'app/Interfaces/Web/Navigation/PropertyNavigationContributor.php',
    'app/Interfaces/Web/Navigation/DiagnosticNavigationContributor.php',
    'app/Interfaces/Web/Service/CompanyHomeService.php',
    'app/Interfaces/Web/View/components/workspace_sidebar.phtml',
    'app/Interfaces/Web/View/components/workspace_topbar.phtml',
    'app/Interfaces/Web/View/components/workspace_mobile_nav.phtml',
    'app/Interfaces/Web/View/components/ui/page_header.phtml',
    'app/Interfaces/Web/View/components/ui/kpi_card.phtml',
    'app/Interfaces/Web/View/components/ui/state.phtml',
    'app/Interfaces/Web/View/components/ui/status_badge.phtml',
    'app/Interfaces/Web/View/components/ui/tabs.phtml',
    'app/Interfaces/Web/View/components/sales/navigation.phtml',
    'app/Interfaces/Web/View/admin/index.phtml',
    'app/Interfaces/Web/View/sales/dashboard.phtml',
    'app/Interfaces/Web/View/sales/today.phtml',
    'app/Interfaces/Web/View/sales/pipeline.phtml',
    'app/Interfaces/Web/View/sales/leads.phtml',
    'app/Interfaces/Web/View/sales/deals.phtml',
    'app/Interfaces/Web/View/sales/deal.phtml',
    'app/Interfaces/Web/View/sales/director.phtml',
    'app/Interfaces/Web/View/sales/admin.phtml',
    'frontend/components/interactive.js',
    'frontend/core/workspace-shell.js',
    'frontend/entrypoints/company-home.js',
    'frontend/entrypoints/terranova-interface.js',
    'frontend/entrypoints/cos-control-center.js',
    'frontend/entrypoints/diagnostics-methodology-studio.js',
    'frontend/entrypoints/sales-workspace.js',
    'frontend/features/home/company-home.css',
    'frontend/features/cos/control-center.css',
    'frontend/features/diagnostics/methodology-studio.css',
    'frontend/features/diagnostics/methodology-studio.js',
    'frontend/features/sales/workspace.css',
    'frontend/features/sales/workspace.js',
    'frontend/layouts/surfaces.css',
    'frontend/styles/interface.css',
    'frontend/styles/foundation.css',
    'frontend/styles/components.css',
    'frontend/styles/patterns.css',
    'frontend/styles/workspace.css',
    'docs/architecture/frontend-interface.md',
    'docs/architecture/web-v0.2.md',
    'docs/architecture/web-v0.3.md',
    'docs/architecture/web-v0.4.md',
    'docs/architecture/web-v0.5.md',
] as $requiredPath) {
    if (!is_file($root . '/' . $requiredPath)) throw new RuntimeException('Frontend interface architecture file is missing: ' . $requiredPath);
}

$companyHome = (string) file_get_contents($root . '/app/Interfaces/Web/Service/CompanyHomeService.php');
foreach ([
    'SalesWorkspaceReadModelInterface',
    'OperationsReadModelInterface',
    'ActiveModuleResolver',
] as $needle) {
    if (!str_contains($companyHome, $needle)) throw new RuntimeException('WEB V0.4 Company Home must compose canonical read boundaries: ' . $needle);
}
foreach (['PdoConnection', 'PDO ', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
    if (str_contains($companyHome, $forbidden)) throw new RuntimeException('Company Home must not become a direct persistence read model: ' . $forbidden);
}

$adminController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/AdminController.php');
foreach (["workspaceSection = 'home'", "workspaceActive = 'home'", "['company-home']", "getShared('frontendCompanyHomeService')"] as $needle) {
    if (!str_contains($adminController, $needle)) throw new RuntimeException('AdminController is missing WEB V0.4 Company Home workspace contract: ' . $needle);
}
$adminHomeView = (string) file_get_contents($root . '/app/Interfaces/Web/View/admin/index.phtml');
if (str_contains($adminHomeView, "partial('shared/manager_header'")) throw new RuntimeException('Company Home must use the layout-owned Workspace shell.');
if (!str_contains($adminHomeView, 'Runtime modules') || !str_contains($adminHomeView, 'Company Home')) throw new RuntimeException('Company Home must expose company pulse and runtime module state.');

$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (["setShared('frontendCompanyHomeService'", "setShared('webModuleNavigationContributors'", "setShared('frontendNavigationService'"] as $needle) {
    if (!str_contains($webServices, $needle)) throw new RuntimeException('Web composition root is missing service: ' . $needle);
}

$managerHeader = (string) file_get_contents($root . '/app/Interfaces/Web/View/shared/manager_header.phtml');
foreach (["getShared('frontendNavigationService')", 'navigationService->workspace', 'navigationService->portal'] as $needle) {
    if (!str_contains($managerHeader, $needle)) throw new RuntimeException('Shared Web shell is missing WEB V0.5 module-aware navigation: ' . $needle);
}

$cosController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/CosController.php');
if (!str_contains($cosController, "workspaceSection = 'cos'") || !str_contains($cosController, "['cos-control-center']")) throw new RuntimeException('COS Control Center must opt into the Workspace shell and its feature bundle.');
$diagnosticController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/MethodologyStudioController.php');
if (!str_contains($diagnosticController, "['diagnostics-methodology-studio']")) throw new RuntimeException('Methodology Studio must load through a Vite feature entrypoint.');

$salesController = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/SalesController.php');
foreach (["workspaceSection = 'sales'", "['sales-workspace']", 'function dealsAction', "workspaceActive = \$active"] as $needle) {
    if (!str_contains($salesController, $needle)) throw new RuntimeException('SalesController is missing WEB V0.3 workspace contract: ' . $needle);
}
$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
if (!str_contains($routes, "'/sales/deals'")) throw new RuntimeException('WEB V0.3 requires the canonical /sales/deals route.');

foreach (glob($root . '/app/Interfaces/Web/View/sales/*.phtml') ?: [] as $salesView) {
    $source = (string) file_get_contents($salesView);
    if (str_contains($source, "partial('shared/manager_header'")) throw new RuntimeException('Sales views must use the layout-owned Workspace shell: ' . $salesView);
}

$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View'));
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') continue;
    $source = (string) file_get_contents($view->getPathname());
    if (str_contains($source, '/assets/js/') || str_contains($source, '/assets/css/')) throw new RuntimeException('PHTML must not bypass Vite with direct /assets JS/CSS references: ' . $view->getPathname());
}

echo "Frontend interface architecture passed: public shell, module-aware workspace/portal navigation and WEB V0.4 composition are enforced.\n";
