<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$contract = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php');
$base = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');
$projection = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
$services = $read('app/Bootstrap/SalesServices.php');
$web = $read('app/Interfaces/Web/Controller/SalesController.php');
$routes = $read('app/Interfaces/Web/Routing/SalesRoutes.php');
$searchController = $read('app/Interfaces/Api/Controller/SalesWorkspaceSearchController.php');
$navigation = $read('app/Interfaces/Web/View/components/sales/navigation.phtml');
$director = $read('app/Interfaces/Web/View/sales/director.phtml');
$js = $read('frontend/features/sales/workspace.js');
$css = $read('frontend/features/sales/workspace-v065.css');
$browser = $read('tests/browser/sales_workspace.mjs');
$workflow = $read('.github/workflows/diagnostic.yml');

$assert(str_contains($contract, 'search('), 'Operational read contract must expose Global Sales Search.');
$assert(str_contains($projection, 'function search('), 'Operational projection must implement Global Sales Search.');
$assert(!str_contains($base, 'search('), 'Global Sales Search must not widen the stable runtime read contract.');
foreach (['customer_phone', 'customer_email', "'groups'", 'at_risk_revenue', 'stale_7d', 'lead_response_minutes', 'followup_completion_rate', 'historical_stage_transitions'] as $marker) {
    $assert(str_contains($projection, $marker), 'Final Sales projection missing: ' . $marker);
}

$assert(str_contains($services, "'salesWorkspaceOperationalReadModel'"), 'Operational projection must be registered in DI.');
$assert(str_contains($services, 'MysqlSalesWorkspaceOperationalReadModel'), 'Composition root must own the concrete projection.');
$assert(str_contains($web, "getShared('salesWorkspaceOperationalReadModel')"), 'Web controller must resolve operational projection through DI.');
$assert(!str_contains($web, 'new MysqlSalesWorkspaceOperationalReadModel'), 'Web controller must not construct MySQL projection directly.');

$assert(str_contains($routes, '/api/sales/search'), 'Global Sales Search route is missing.');
$normalizedRoutes = preg_replace('/\s+/', '', $routes) ?? $routes;
$assert(str_contains($normalizedRoutes, "'controller'=>'sales_workspace_search'"), 'Global Sales Search controller route is missing.');
foreach (['isManager', 'salesWorkspaceOperationalReadModel', 'organization()->id()', 'mb_strlen($query) < 2'] as $marker) {
    $assert(str_contains($searchController, $marker), 'Search API boundary missing: ' . $marker);
}
foreach (['data-sales-global-search', 'data-sales-global-search-input', 'api/sales/search', 'sales/deals'] as $marker) {
    $assert(str_contains($navigation, $marker), 'Global Sales Search UI missing: ' . $marker);
}
foreach (['initSalesGlobalSearch', 'AbortController', 'metaKey', 'data-sales-global-search-results', 'setStatus'] as $marker) {
    $assert(str_contains($js, $marker), 'Global/async Sales UX missing: ' . $marker);
}
$assert(!str_contains($js, 'window.alert'), 'Sales Workspace must use inline operation states instead of modal alert errors.');
foreach (['tn-sales-global-search__results', 'data-state="error"', 'tn-sales-control-status'] as $marker) {
    $assert(str_contains($css, $marker), 'V0.6.5 Sales styling missing: ' . $marker);
}
foreach (['At-risk revenue', 'Stale 7d', 'Response', 'Follow-up', 'Historical stage transitions'] as $marker) {
    $assert(str_contains($director, $marker), 'Director final metric UX missing: ' . $marker);
}
foreach (['playwright-core', '/sales/today', '/sales/leads', '/sales/pipeline', 'data-sales-global-search', 'SALES_E2E_MUTATION_SAFE'] as $marker) {
    $assert(str_contains($browser, $marker), 'Browser smoke missing: ' . $marker);
}
foreach (['php tests/unit/sales_v065.php', 'node --check tests/browser/sales_workspace.mjs'] as $marker) {
    $assert(str_contains($workflow, $marker), 'Main runtime workflow is not gating V0.6.5: ' . $marker);
}

foreach ([
    'app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php',
    'app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php',
    'app/Bootstrap/SalesServices.php',
    'app/Interfaces/Web/Controller/SalesController.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Api/Controller/SalesWorkspaceSearchController.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed: ' . $file . ' ' . implode("\n", $output));
}

echo "Sales V0.6.5 final EPIC 2 contract passed on the V0.6.8 historical-funnel and mutation-safe E2E semantics.\n";
