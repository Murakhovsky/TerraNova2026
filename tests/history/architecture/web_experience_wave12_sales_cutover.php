<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function expectSalesCutover(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$routes = file_get_contents($root . '/symfony/config/routes.yaml');
$production = file_get_contents($root . '/symfony/src/Web/Sales/SalesWorkspaceController.php');
$dashboardController = file_get_contents($root . '/symfony/src/Web/Sales/SalesDashboardController.php');
$leadsController = file_get_contents($root . '/symfony/src/Web/Sales/SalesLeadsController.php');
$services = file_get_contents($root . '/symfony/config/services.yaml');
$leadRuntime = file_get_contents($root . '/symfony/assets/controllers/sales_lead_controller.js');
$leadList = file_get_contents($root . '/symfony/templates/experience/sales/leads.html.twig');
$salesBrowser = file_get_contents($root . '/tests/browser/sales_workspace.mjs');
$provider = file_get_contents($root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');
$workspaceResolver = file_get_contents($root . '/symfony/src/Web/Experience/Workspace/WorkspaceCompositionResolver.php');
$workspaceShell = file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
$activityExtension = file_get_contents($root . '/symfony/templates/experience/workspace/extensions/sales/activity.html.twig');
$aiExtension = file_get_contents($root . '/symfony/templates/experience/workspace/extensions/sales/ai_context.html.twig');
$frontendController = file_get_contents($root . '/symfony/src/Http/Api/V1/Controller/SalesFrontendController.php');
$mutationHandler = file_get_contents($root . '/symfony/src/Application/Sales/Command/SalesFrontendMutationCommandHandler.php');
$salesWrite = file_get_contents($root . '/app/Domains/Sales/Application/Service/SalesWriteService.php');
$followup = file_get_contents($root . '/app/Domains/Sales/Application/UseCase/ScheduleLeadFollowup.php');
$actionService = file_get_contents($root . '/app/Kernel/Action/Service/ActionService.php');
$approvalService = file_get_contents($root . '/app/Kernel/Approval/Service/ApprovalService.php');

foreach ([
    "cos_web_sales_dashboard:\n  path: /sales/dashboard\n  controller: App\\Web\\Sales\\SalesDashboardController::index",
    "cos_web_sales_leads:\n  path: /sales/leads\n  controller: App\\Web\\Sales\\SalesLeadsController::index",
    "cos_web_sales_lead:\n  path: /sales/leads/{id}\n  controller: App\\Web\\Sales\\SalesWorkspaceController::lead",
] as $contract) {
    expectSalesCutover(str_contains($routes, $contract), 'Production Sales route is not cut over: ' . $contract);
}

foreach ([
    '/sales/reference/',
    'SalesReferenceController',
    'cos_web_sales_reference_',
] as $forbidden) {
    expectSalesCutover(!str_contains($routes, $forbidden), 'Reference Sales route residue remains: ' . $forbidden);
}

expectSalesCutover(!is_file($root . '/symfony/src/Web/Sales/SalesReferenceController.php'), 'Reference Sales controller file must be removed.');
expectSalesCutover(!is_file($root . '/app/Interfaces/Web/View/sales/dashboard.phtml'), 'Legacy Sales dashboard PHTML must be removed.');
expectSalesCutover(!is_file($root . '/app/Interfaces/Web/View/sales/leads.phtml'), 'Legacy Sales leads PHTML must be removed.');
expectSalesCutover(!is_file($root . '/symfony/src/Web/Sales/SalesPageController.php'), 'Legacy SalesPageController must stay retired after VR-008.');
expectSalesCutover(str_contains($services, 'App\\Web\\Sales\\SalesWorkspaceController:'), 'Production SalesWorkspaceController service wiring is missing.');
expectSalesCutover(str_contains($services, 'App\\Web\\Sales\\SalesDashboardController:'), 'Production SalesDashboardController service wiring is missing.');
expectSalesCutover(str_contains($services, 'App\\Web\\Sales\\SalesLeadsController:'), 'Production SalesLeadsController service wiring is missing.');

foreach ([
    '/sales/reference/dashboard',
    '/sales/reference/leads',
    'reference_dashboard.html.twig',
    'reference_leads.html.twig',
    'reference_lead_workspace.html.twig',
] as $forbidden) {
    expectSalesCutover(!str_contains($production, $forbidden), 'Production Sales controller retains reference path/template residue: ' . $forbidden);
}

foreach ([
    'symfony/templates/experience/sales/dashboard.html.twig',
    'symfony/templates/experience/sales/leads.html.twig',
    'symfony/templates/experience/sales/lead_workspace.html.twig',
] as $template) {
    expectSalesCutover(is_file($root . '/' . $template), 'Production Sales Twig template missing: ' . $template);
}

foreach ([
    'data-sales-lead-status',
    'data-sales-lead-owner',
    'data-sales-lead-deal',
    'data-sales-lead-followup',
    'data-controller="sales-lead"',
] as $marker) {
    expectSalesCutover(str_contains($leadList, $marker), 'Lead mutation parity missing from production Twig: ' . $marker);
}

foreach ([
    '/api/v1/sales/leads/',
    '/opportunity',
    '/followups',
    "'PATCH'",
    "'X-CSRF-Token'",
    "'X-Idempotency-Key'",
] as $marker) {
    expectSalesCutover(str_contains($leadRuntime, $marker), 'Lead mutation runtime contract missing: ' . $marker);
}

expectSalesCutover(str_contains($leadsController, 'SalesAdminQuery'), 'Production Sales Leads controller must source owner choices through QueryBus.');
expectSalesCutover(str_contains($leadsController, "'team.users'"), 'Production Sales owner projection must use the canonical Sales admin query operation.');

foreach ([
    '.cos-shell[data-controller="workspace-shell"]',
    '[data-sales-surface="lead-list"]',
    'Qualified Lead must persist after reload',
] as $marker) {
    expectSalesCutover(str_contains($salesBrowser, $marker), 'Sales browser E2E is not aligned with canonical Lead cutover: ' . $marker);
}


/* Permissions + tenant isolation */
foreach ([
    'TenantContextProviderInterface',
    'isManager()',
    '$tenant->organizationId()',
    'QueryBusInterface',
] as $marker) {
    expectSalesCutover(str_contains($production, $marker), 'Production Sales permission/tenant contract missing: ' . $marker);
}
expectSalesCutover(!str_contains($production, "query->get('organization"), 'Production Sales must never accept organization identity from query parameters.');
expectSalesCutover(str_contains($workspaceResolver, 'does not belong to the authenticated organization'), 'Workspace tenant isolation guard is missing.');

/* Mobile + agent integration */
foreach (['MOBILE_PRIMARY', 'MOBILE_MENU', 'AI_PROPOSAL'] as $marker) {
    expectSalesCutover(str_contains($provider, $marker), 'Sales platform capability missing: ' . $marker);
}
expectSalesCutover(str_contains($provider, 'WorkspaceSlot::Ai'), 'Sales Workspace AI extension is missing.');
expectSalesCutover(str_contains($aiExtension, 'governed actions'), 'Sales AI context must expose governed actions.');
expectSalesCutover(str_contains($salesBrowser, "name: 'mobile'"), 'Sales browser E2E must exercise mobile composition.');

/* Realtime */
foreach (['data-cos-realtime-state', 'cos:realtime-update'] as $marker) {
    expectSalesCutover(str_contains($workspaceShell, $marker), 'Workspace realtime contract missing: ' . $marker);
}
expectSalesCutover(str_contains($activityExtension, 'workspace.entityKey()'), 'Sales activity projection must remain entity-scoped.');

/* Performance */
foreach (['salesPerformanceBudget', 'navigationMs', 'domNodes', 'transferBytes'] as $marker) {
    expectSalesCutover(str_contains($salesBrowser, $marker), 'Authenticated Sales performance coverage missing: ' . $marker);
}

/* Audit + correlation */
foreach (['correlationId', 'X-Idempotency-Key'] as $marker) {
    expectSalesCutover(str_contains($frontendController, $marker), 'Sales frontend mutation correlation/idempotency contract missing: ' . $marker);
}
foreach ([$salesWrite, $followup] as $source) {
    expectSalesCutover(str_contains($source, 'EventMetadata'), 'Sales mutation path must publish correlated event metadata.');
}
expectSalesCutover(str_contains($mutationHandler, 'ActionService'), 'Sales governed action path must use Kernel ActionService.');
expectSalesCutover(str_contains($mutationHandler, 'ApprovalService'), 'Sales approval path must use Kernel ApprovalService.');
expectSalesCutover(str_contains($actionService, 'AuditRepositoryInterface'), 'Kernel action execution audit path is missing.');
expectSalesCutover(str_contains($approvalService, 'AuditRepositoryInterface'), 'Kernel approval audit path is missing.');

echo "Wave 12.26 Sales Cutover architecture gate passed.\n";
