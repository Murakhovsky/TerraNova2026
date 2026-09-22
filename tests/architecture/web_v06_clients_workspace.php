<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing WEB V0.6 Clients Workspace artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $content, string $needle, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$notContains = static function (string $content, string $needle, string $message): void {
    if (str_contains($content, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$controller = $read('symfony/src/Web/Sales/ClientCasePageController.php');
$routes = $read('symfony/config/routes.yaml');
$layout = $read('app/Interfaces/Web/View/index.phtml');
$managerHeader = $read('app/Interfaces/Web/View/shared/manager_header.phtml');
$entrypoint = $read('frontend/entrypoints/clients-workspace.js');
$interfaceEntrypoint = $read('frontend/entrypoints/terranova-interface.js');
$productionJs = $read('frontend/core/production.js');
$clientCss = $read('frontend/features/clients/workspace.css');
$vite = $read('vite.config.js');
$assetTest = $read('tests/architecture/frontend_assets.php');

foreach ([
    'final readonly class ClientCasePageController',
    'public function index(Request $request): Response',
    'public function inbox(Request $request): Response',
    'public function show(Request $request,string $id): Response',
    "'workspaceSection'=>'clients'",
    "'pageAssetEntries'=>['clients-workspace']",
    "'client_case/index'",
    "'client_case/inbox'",
    "'client_case/show'",
    '$this->manager()',
    '$this->csrf->isValid($r)',
] as $needle) {
    $contains($controller, $needle, 'Canonical Client Case controller is missing the workspace contract.');
}
$notContains($controller, 'Domains\\Clients', 'Client Case presentation must not invent a Clients Domain.');

foreach ([
    'path: /client-case',
    'ClientCasePageController::index',
    'path: /client-case/inbox',
    'ClientCasePageController::inbox',
    'path: /client-case/show/{id}',
    'ClientCasePageController::show',
    'path: /client-case/create',
    'ClientCasePageController::create',
    'path: /client-case/update/{id}',
    'ClientCasePageController::update',
    'path: /client-case/quickUpdate/{id}',
    'ClientCasePageController::quickUpdate',
    'path: /client-case/activity/{id}',
    'ClientCasePageController::activity',
] as $needle) {
    $contains($routes, $needle, 'Canonical Client Case routes are incomplete.');
}

$contains($layout, "'layoutOwned' => true", 'Global Web layout must keep the shared Workspace shell layout-owned.');
foreach (['$layoutOwned', '$workspaceSection', 'if (!$layoutOwned && $workspaceSection !== \'\')'] as $needle) {
    $contains($managerHeader, $needle, 'Shared manager header is missing the duplicate-shell guard.');
}

foreach (['inbox.phtml', 'index.phtml', 'show.phtml'] as $viewFile) {
    $view = $read('app/Interfaces/Web/View/client_case/' . $viewFile);
    $contains($view, "partial('shared/manager_header'", 'Client Case view must remain covered by the shared shell guard: ' . $viewFile);
    $contains($view, 'tn-client-workspace', 'Client Case view must declare canonical workspace scoping directly: ' . $viewFile);
    $notContains($view, 'tn-listing-hero', 'Client Case view must not restore the legacy hero: ' . $viewFile);
    $notContains($view, 'tn-admin-panel', 'Client Case view must not restore legacy admin panels: ' . $viewFile);
    $notContains($view, 'tn-empty-state', 'Client Case view must use canonical State instead of legacy empty state: ' . $viewFile);
    $notContains($view, '/assets/js/', 'Clients Workspace view must not bypass Vite: ' . $viewFile);
    $notContains($view, '/assets/css/', 'Clients Workspace view must not bypass Vite: ' . $viewFile);
}

$contains($entrypoint, "../features/clients/workspace.css", 'Clients Workspace Vite entrypoint must retain the domain CSS.');
$notContains($entrypoint, "../features/clients/workspace.js", 'Clients Workspace entrypoint must not restore the retired scoping script.');

foreach (["import { initProductionUX } from '../core/production.js'", 'initProductionUX();'] as $needle) {
    $contains($interfaceEntrypoint, $needle, 'Shared Workspace entrypoint must initialize production form behavior.');
}
foreach (["addEventListener('submit'", 'dataset.submitting', "setAttribute('aria-busy', 'true')", "classList.add('is-pending')", "addEventListener('pageshow'"] as $needle) {
    $contains($productionJs, $needle, 'Shared production form guard is incomplete.');
}
foreach (['.tn-client-workspace', '.tn-case-funnel', '.tn-inbox-card', '.tn-ai-deal-card', '@media (max-width: 650px)'] as $needle) {
    $contains($clientCss, $needle, 'Clients Workspace responsive styling is incomplete.');
}
$contains($vite, "'clients-workspace': resolve(import.meta.dirname, 'frontend/entrypoints/clients-workspace.js')", 'Vite must expose Clients Workspace.');
$contains($assetTest, "'clients-workspace'", 'Frontend asset architecture must validate Clients Workspace.');

echo "WEB V0.6 Clients Workspace canonical Symfony contract passed.\n";
