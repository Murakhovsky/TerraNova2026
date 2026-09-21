<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$contract = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php');
$projection = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
$base = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');
$services = $read('app/Bootstrap/SalesServices.php');
$web = $read('symfony/src/Web/Sales/SalesPageController.php');
$today = $read('app/Interfaces/Web/View/sales/today.phtml');
$leads = $read('symfony/templates/experience/sales/leads.html.twig');
$leadController = $read('symfony/assets/controllers/sales_lead_controller.js');
$pipeline = $read('app/Interfaces/Web/View/sales/pipeline.phtml');
$deal = $read('app/Interfaces/Web/View/sales/deal.phtml');
$deals = $read('app/Interfaces/Web/View/sales/deals.phtml');
$director = $read('app/Interfaces/Web/View/sales/director.phtml');
$js = $read('frontend/features/sales/workspace.js');

foreach (['communications(', 'approvals(', 'directorAnalytics('] as $marker) {
    $assert(str_contains($contract, $marker), 'Operational projection contract missing: ' . $marker);
    $assert(str_contains($projection, $marker), 'Operational projection missing: ' . $marker);
    $assert(!str_contains($base, $marker), 'EPIC 2 projection leaked into stable read contract: ' . $marker);
}
foreach (['attention_reason', 'days_in_stage', 'weighted_value', 'avg_days_in_stage', 'needs_approval', 'historical_stage_transitions'] as $marker) {
    $assert(str_contains($projection, $marker), 'Projection missing: ' . $marker);
}
foreach (['workspace->communications(', 'workspace->approvals(', 'SalesDirectorCockpitService', 'SalesWorkspaceOperationalReadModelInterface'] as $marker) {
    $assert(str_contains($web, $marker), 'Symfony Web composition missing: ' . $marker);
}
$assert(str_contains($services, 'MysqlSalesWorkspaceOperationalReadModel'), 'Composition root must own the concrete operational read model.');
$assert(!str_contains($web, 'new MysqlSalesWorkspaceOperationalReadModel'), 'Web controller must not construct Infrastructure projections directly.');
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
foreach (['name="owner_id"', 'name="priority"', 'name="source"', 'attention_reason'] as $marker) {
    $assert(str_contains($deals, $marker), 'Deals list missing: ' . $marker);
}
foreach (['Funnel', 'Historical stage transitions', 'Pipeline health', 'Manager performance', 'Pending approvals'] as $marker) {
    $assert(str_contains($director, $marker), 'Director missing: ' . $marker);
}
foreach (['initLeadInbox', 'initToday', 'data-sales-approval', "operation === 'message'", '/api/v1/sales/actions/', 'data-decision="execute"', 'error.status === 409', 'concurrent_stage_change'] as $marker) {
    $assert(str_contains($js, $marker), 'JS missing: ' . $marker);
}

foreach ([
    'app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php',
    'app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php',
    'app/Bootstrap/SalesServices.php',
    'symfony/src/Web/Sales/SalesPageController.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed: ' . $file . ' ' . implode("\n", $output));
}

echo "Sales V0.6.3 workspace projection and UX wiring contract passed on the V0.6.8 historical-funnel and target-drawer semantics.\n";
