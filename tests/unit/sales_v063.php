<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$contract = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php');
$projection = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
$base = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');
$services = $read('app/Bootstrap/SalesServices.php');
$web = $read('app/Interfaces/Web/Controller/SalesController.php');
$today = $read('app/Interfaces/Web/View/sales/today.phtml');
$leads = $read('app/Interfaces/Web/View/sales/leads.phtml');
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
foreach (['attention_reason', 'days_in_stage', 'weighted_value', 'avg_days_in_stage', 'needs_approval', 'current_state_cohort'] as $marker) {
    $assert(str_contains($projection, $marker), 'Projection missing: ' . $marker);
}
foreach (["'communications' => \$q->communications", "'approvals' => \$q->approvals", 'directorAnalytics', 'salesWorkspaceOperationalReadModel'] as $marker) {
    $assert(str_contains(str_replace(' ', '', $web), str_replace(' ', '', $marker)), 'Web composition missing: ' . $marker);
}
$assert(str_contains($services, 'MysqlSalesWorkspaceOperationalReadModel'), 'Composition root must own the concrete operational read model.');
$assert(!str_contains($web, 'new MysqlSalesWorkspaceOperationalReadModel'), 'Web controller must not construct Infrastructure projections directly.');
foreach (['Needs My Approval', 'data-sales-today-root', 'data-sales-activity-complete', 'data-sales-activity-reschedule', 'My Work', 'Team'] as $marker) {
    $assert(str_contains($today, $marker), 'Today missing: ' . $marker);
}
foreach (['data-sales-lead-inbox', 'data-sales-lead-drawer', 'data-sales-lead-status', 'data-sales-lead-owner', 'data-sales-lead-deal', 'data-sales-lead-followup'] as $marker) {
    $assert(str_contains($leads, $marker), 'Lead Inbox missing: ' . $marker);
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
foreach (['Funnel', 'Pipeline health', 'Manager performance', 'Pending approvals', 'Current-state'] as $marker) {
    $assert(str_contains($director, $marker), 'Director missing: ' . $marker);
}
foreach (['initLeadInbox', 'initToday', 'data-sales-approval', "message: 'messages'", '/api/sales/actions/', 'data-decision="execute"', 'error.status === 409', 'concurrent_stage_change'] as $marker) {
    $assert(str_contains($js, $marker), 'JS missing: ' . $marker);
}

foreach ([
    'app/Domains/Sales/Application/Contract/SalesWorkspaceOperationalReadModelInterface.php',
    'app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php',
    'app/Bootstrap/SalesServices.php',
    'app/Interfaces/Web/Controller/SalesController.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed: ' . $file . ' ' . implode("\n", $output));
}

echo "Sales V0.6.3 workspace projection and UX wiring contract passed.\n";
