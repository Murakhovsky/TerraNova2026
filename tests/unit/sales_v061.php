<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = $read('app/Interfaces/Web/Routing/SalesRoutes.php');
$api = $read('app/Interfaces/Api/Controller/SalesController.php');
$web = $read('app/Interfaces/Web/Controller/SalesController.php');
$readModel = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceReadModel.php');
$operations = $read('app/Domains/Sales/Application/Service/SalesOperationService.php');
$operationRepository = $read('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesOperationRepository.php');
$today = $read('app/Interfaces/Web/View/sales/today.phtml');
$leads = $read('app/Interfaces/Web/View/sales/leads.phtml');
$pipeline = $read('app/Interfaces/Web/View/sales/pipeline.phtml');
$deal = $read('app/Interfaces/Web/View/sales/deal.phtml');
$deals = $read('app/Interfaces/Web/View/sales/deals.phtml');
$director = $read('app/Interfaces/Web/View/sales/director.phtml');
$js = $read('frontend/features/sales/workspace.js');

foreach ([
    "addGet('/sales'", '/messages', '/activities/{activityId:[0-9]+}/complete', '/activities/{activityId:[0-9]+}/reschedule',
    '/leads/{id:[0-9]+}/status', '/leads/{id:[0-9]+}/owner', '/leads/{id:[0-9]+}/deal', '/leads/{id:[0-9]+}/followups',
    '/approvals/{id:[a-f0-9]{32}}/approve', '/approvals/{id:[a-f0-9]{32}}/reject',
    '/actions/{id:[a-f0-9]{32}}/execute', '/actions/{id:[a-f0-9]{32}}/dismiss',
] as $marker) {
    $assert(str_contains($routes, $marker), 'Sales V0.6.1 route missing: ' . $marker);
}

foreach (['SendMessageCommand', 'concurrent_stage_change', 'cosApprovalService', 'cosActionService', 'salesInboundService'] as $marker) {
    $assert(str_contains($api, $marker), 'Sales V0.6.1 API contract missing: ' . $marker);
}
$assert(str_contains($api, '? 409 : 422'), 'Concurrent stage changes must return HTTP 409 to the workspace.');
$assert(str_contains($web, "'communications' => $q->communications"), 'Deal Workspace must load first-class communications.');
$assert(str_contains($web, "'approvals' => $q->approvals"), 'Deal Workspace must load pending approvals.');
$assert(str_contains($web, 'directorAnalytics'), 'Director workspace must use the Sales director projection.');

foreach (['needs_approval', 'attention_reason', 'days_in_stage', 'weighted_value', 'avg_days_in_stage', 'communications(', 'approvals(', 'directorAnalytics('] as $marker) {
    $assert(str_contains($readModel, $marker), 'Sales read model missing V0.6.1 projection: ' . $marker);
}
foreach (['completeActivity(', 'rescheduleActivity('] as $marker) {
    $assert(str_contains($operations, $marker), 'Sales operation service missing lifecycle method: ' . $marker);
    $assert(str_contains($operationRepository, $marker), 'Sales operation repository missing lifecycle persistence: ' . $marker);
}

foreach (['Needs My Approval', 'data-sales-today-root', 'data-sales-activity-complete', 'data-sales-activity-reschedule'] as $marker) {
    $assert(str_contains($today, $marker), 'Today 2.0 missing: ' . $marker);
}
foreach (['data-sales-lead-drawer', 'data-sales-lead-status', 'data-sales-lead-owner', 'data-sales-lead-deal', 'data-sales-lead-followup'] as $marker) {
    $assert(str_contains($leads, $marker), 'Lead Inbox missing: ' . $marker);
}
foreach (['weighted_value', 'avg_days_in_stage', 'days_in_stage', 'attention_reason', 'name="owner_id"', 'name="priority"'] as $marker) {
    $assert(str_contains($pipeline, $marker), 'Pipeline 2.0 missing: ' . $marker);
}
foreach (['id="communications"', 'Next Action', 'data-operation="message"', 'data-sales-approval', 'COS Intelligence'] as $marker) {
    $assert(str_contains($deal, $marker), 'Deal Workspace 2.0 missing: ' . $marker);
}
foreach (['name="owner_id"', 'name="priority"', 'name="source"', 'attention_reason'] as $marker) {
    $assert(str_contains($deals, $marker), 'Deals list missing filter/attention contract: ' . $marker);
}
foreach (['Funnel', 'Pipeline health', 'Manager performance', 'Pending approvals'] as $marker) {
    $assert(str_contains($director, $marker), 'Director 2.0 missing: ' . $marker);
}

foreach (['initLeadInbox', 'initToday', 'data-sales-approval', "message: 'messages'", '/api/sales/actions/', "data-decision=\"execute\"", 'error.status === 409'] as $marker) {
    $assert(str_contains($js, $marker), 'Sales workspace JS missing V0.6.1 interaction: ' . $marker);
}

// This test intentionally verifies cross-layer contracts. Browser automation is a separate smoke layer;
// the regression here prevents a future UI refactor from silently bypassing canonical Sales/Kernel use cases.
echo "Sales V0.6.1 workspace completion contract passed.\n";
