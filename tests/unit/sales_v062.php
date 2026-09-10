<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = $read('app/Interfaces/Web/Routing/SalesRoutes.php');
$controller = $read('app/Interfaces/Api/Controller/SalesWorkspaceActionsController.php');
$service = $read('app/Domains/Sales/Application/Service/SalesOperationService.php');
$repository = $read('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesOperationRepository.php');
$workspaceContract = $read('app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');

foreach ([
    '/messages',
    '/activities/{activityId:[0-9]+}/complete',
    '/activities/{activityId:[0-9]+}/reschedule',
    '/leads/{id:[0-9]+}/status',
    '/leads/{id:[0-9]+}/owner',
    '/leads/{id:[0-9]+}/deal',
    '/leads/{id:[0-9]+}/followups',
    '/approvals/{id:[a-f0-9]{32}}/approve',
    '/approvals/{id:[a-f0-9]{32}}/reject',
    '/actions/{id:[a-f0-9]{32}}/execute',
    '/actions/{id:[a-f0-9]{32}}/dismiss',
] as $marker) {
    $assert(str_contains($routes, $marker), 'Sales V0.6.2 route missing: ' . $marker);
}
$assert(str_contains($routes, "'sales_workspace_actions'"), 'Operational routes must use the Sales workspace action facade.');

foreach ([
    'messageAction', 'completeActivityAction', 'rescheduleActivityAction',
    'leadStatusAction', 'leadOwnerAction', 'leadDealAction', 'leadFollowupAction',
    'approveAction', 'rejectAction', 'executeAction', 'dismissAction',
    'salesInboundService', 'salesOperationService', 'cosApprovalService', 'cosActionService', 'cosJobQueue',
] as $marker) {
    $assert(str_contains($controller, $marker), 'Sales action facade missing contract marker: ' . $marker);
}
$assert(str_contains($controller, "strtoupper(\$approval->approverType) === 'USER'"), 'USER approval ownership must be enforced.');
$assert(str_contains($controller, 'ActionExecutionJobHandler::TYPE'), 'Action execution must use the Kernel queue handler type.');
$assert(str_contains($controller, 'Pending approvals must be rejected through ApprovalService'), 'Dismiss flow must document the approval/action consistency boundary.');

foreach (['completeActivity(', 'rescheduleActivity(', 'FOLLOWUP_COMPLETED', 'TASK_COMPLETED', 'MEETING_COMPLETED'] as $marker) {
    $assert(str_contains($service, $marker), 'SalesOperationService missing lifecycle behavior: ' . $marker);
}
foreach (['completeActivity(', 'rescheduleActivity(', 'organization_id=:org', 'client_case_id=:deal', 'completed_at IS NULL'] as $marker) {
    $assert(str_contains($repository, $marker), 'Sales operation persistence missing tenant-safe lifecycle predicate: ' . $marker);
}

// V0.6.6 hardening: Deal.next_contact_at is a projection of open follow-ups, not of arbitrary activities.
foreach (['syncNextContact(', 'MIN(due_at)', 'activity_type="followup"'] as $marker) {
    $assert(str_contains($repository, $marker), 'Follow-up next-contact projection missing: ' . $marker);
}
$assert(!str_contains($repository, 'SET next_contact_at=:due'), 'Rescheduling a meeting/task must not overwrite Deal.next_contact_at directly.');

// V0.6.1 put UX-specific projections on the stable read contract too early. V0.6.2
// restores the base contract so runtime implementations cannot be broken by an unfinished UI slice.
foreach (['communications(', 'approvals(', 'directorAnalytics('] as $marker) {
    $assert(!str_contains($workspaceContract, $marker), 'UX projection leaked into the stable SalesWorkspaceReadModelInterface: ' . $marker);
}

foreach ([
    'app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesOperationRepository.php',
    'app/Domains/Sales/Application/Service/SalesOperationService.php',
    'app/Interfaces/Api/Controller/SalesWorkspaceActionsController.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed for ' . $file . ': ' . implode("\n", $output));
}

echo "Sales V0.6.2 operational runtime contract passed.\n";
