<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$contract = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php');
$projection = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
$base = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');
$services = $read('app/Bootstrap/SalesServices.php');
$today = $read('symfony/templates/experience/sales/today.html.twig');
$todayController = $read('symfony/assets/controllers/sales_today_controller.js');
$leads = $read('symfony/templates/experience/sales/leads.html.twig');
$leadController = $read('symfony/assets/controllers/sales_lead_controller.js');
$pipeline = $read('symfony/templates/experience/sales/pipeline.html.twig');
$pipelinePresenter = $read('symfony/src/Web/Sales/SalesPipelinePresenter.php');
$pipelineController = $read('symfony/assets/controllers/sales_pipeline_controller.js');
$deal = $read('symfony/templates/experience/sales/deal_workspace.html.twig');
$dealHandler = $read('symfony/src/Application/Sales/Query/GetSalesDealWorkspaceQueryHandler.php');
$dealController = $read('symfony/assets/controllers/sales_deal_controller.js');
$deals = $read('symfony/templates/experience/sales/deals.html.twig');
$dealsPresenter = $read('symfony/src/Web/Sales/SalesDealsPresenter.php');
$dealsController = $read('symfony/assets/controllers/sales_deals_controller.js');
$director = $read('symfony/templates/experience/sales/director.html.twig');
$directorHandler = $read('symfony/src/Application/Sales/Query/GetSalesDirectorDashboardQueryHandler.php');
$directorPresenter = $read('symfony/src/Web/Sales/SalesDirectorPresenter.php');
$js = $read('frontend/features/sales/workspace.js');

foreach (['communications(', 'approvals(', 'directorAnalytics('] as $marker) {
    $assert(str_contains($contract, $marker), 'Operational projection contract missing: ' . $marker);
    $assert(str_contains($projection, $marker), 'Operational projection missing: ' . $marker);
    $assert(!str_contains($base, $marker), 'EPIC 2 projection leaked into stable read contract: ' . $marker);
}
foreach (['attention_reason', 'days_in_stage', 'weighted_value', 'avg_days_in_stage', 'needs_approval', 'historical_stage_transitions'] as $marker) {
    $assert(str_contains($projection, $marker), 'Projection missing: ' . $marker);
}
$assert(str_contains($directorHandler, 'SalesDirectorCockpitService'), 'Director Application Query must own cockpit orchestration.');
foreach (['sales->communications(', 'sales->approvals(', 'OperationsReadModelInterface', 'SalesTeamAdministrationInterface'] as $marker) {
    $assert(str_contains($dealHandler, $marker), 'Deal Workspace query composition missing: ' . $marker);
}
$assert(str_contains($services, 'MysqlSalesWorkspaceOperationalReadModel'), 'Composition root must own the concrete operational read model.');
foreach (['Needs My Approval', 'data-sales-today-root', 'data-sales-activity-complete', 'data-sales-activity-reschedule', 'My Work', 'Team'] as $marker) {
    $assert(str_contains($today, $marker), 'Today missing: ' . $marker);
}
foreach (['data-lead-id', 'data-sales-lead-status', 'data-sales-lead-owner', 'data-sales-lead-deal', 'data-sales-lead-followup', 'sales-lead#status', 'sales-lead#owner', 'sales-lead#convert', 'sales-lead#followup'] as $marker) {
    $assert(str_contains($leads, $marker), 'Canonical Lead Inbox missing operational contract: ' . $marker);
}
foreach (['/api/v1/sales/leads/', 'PATCH', '/opportunity', '/followups', 'X-CSRF-Token', 'X-Idempotency-Key'] as $marker) {
    $assert(str_contains($leadController, $marker), 'Canonical Lead Stimulus controller missing mutation contract: ' . $marker);
}
foreach (['weighted_value', 'avg_days_in_stage', 'days_in_stage', 'attention_reason', 'name="owner_id"', 'name="priority"', 'name="source"'] as $marker) {
    $assert(str_contains($pipeline, $marker), 'Pipeline missing: ' . $marker);
}
foreach (['id="communications"', 'Next Action', 'data-operation="message"', 'data-sales-approval', 'COS Intelligence'] as $marker) {
    $assert(str_contains($deal, $marker), 'Deal missing: ' . $marker);
}
foreach (['CosDataGrid', 'sales-deals', 'cos:datagrid-row-action->sales-deals#rowAction'] as $marker) {
    $assert(str_contains($deals, $marker), 'Canonical Deals Collection missing: ' . $marker);
}
foreach (['owner_id', 'priority', 'source', 'pipeline_id', 'stage_id', 'risk'] as $marker) {
    $assert(str_contains($dealsPresenter, $marker), 'Deals DataGrid filter projection missing: ' . $marker);
}
$assert(str_contains($dealsController, '/sales/deals/'), 'Deals DataGrid row action must navigate to Deal Workspace.');
foreach (['Historical Sales Intelligence', 'Historical funnel', 'Manager performance', 'Risk & explainability', 'CosDataGrid'] as $marker) {
    $assert(str_contains($director, $marker), 'Canonical Director dashboard missing: ' . $marker);
}
foreach (['pipeline_by_currency', 'stage_conversion', 'manager_performance', 'at_risk'] as $marker) {
    $assert(str_contains($directorPresenter, $marker), 'Director presenter projection missing: ' . $marker);
}
foreach (['concurrent_stage_change', '/api/v1/sales/opportunities/', '/stage', 'X-CSRF-Token', 'X-Idempotency-Key'] as $marker) {
    $assert(str_contains($pipelineController, $marker), 'Canonical Pipeline Stimulus controller missing: ' . $marker);
}
foreach (['/api/v1/sales/approvals/', '/activities/', '/complete', '/reschedule', 'X-CSRF-Token', 'X-Idempotency-Key'] as $marker) {
    $assert(str_contains($todayController, $marker), 'Sales Today Stimulus controller missing: ' . $marker);
}
foreach (['/api/v1/sales/actions/', '/communications', 'refreshIntelligence', 'click->sales-deal#decision', 'X-Idempotency-Key'] as $marker) {
    $assert(str_contains($dealController, $marker), 'Deal Stimulus controller missing: ' . $marker);
}

foreach ([
    'app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php',
    'app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php',
    'app/Bootstrap/SalesServices.php',
    'symfony/src/Application/Sales/Query/GetSalesDealWorkspaceQueryHandler.php',
    'symfony/src/Web/Sales/SalesDealController.php',
    'symfony/src/Web/Sales/SalesDealPresenter.php',
    'symfony/src/Application/Sales/Query/GetSalesTodayQueryHandler.php',
    'symfony/src/Web/Sales/SalesTodayController.php',
    'symfony/src/Web/Sales/SalesTodayPresenter.php',
    'symfony/src/Application/Sales/Query/GetSalesPipelineWorkspaceQueryHandler.php',
    'symfony/src/Web/Sales/SalesPipelineController.php',
    'symfony/src/Web/Sales/SalesPipelinePresenter.php',
    'symfony/src/Application/Sales/Query/GetSalesDealsCollectionQueryHandler.php',
    'symfony/src/Web/Sales/SalesDealsController.php',
    'symfony/src/Web/Sales/SalesDealsPresenter.php',
    'symfony/src/Application/Sales/Query/GetSalesDirectorDashboardQueryHandler.php',
    'symfony/src/Web/Sales/SalesDirectorController.php',
    'symfony/src/Web/Sales/SalesDirectorPresenter.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed: ' . $file . ' ' . implode("\n", $output));
}

echo "Sales V0.6.3 workspace projection and UX wiring contract passed on the V0.6.8 historical-funnel and target-drawer semantics.\n";
