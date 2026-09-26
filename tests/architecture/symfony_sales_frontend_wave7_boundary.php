<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) {
        throw new RuntimeException('Missing Wave 7 file: ' . $path);
    }
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

foreach ([
    'symfony/assets/controllers/sales_lead_controller.js',
    'symfony/assets/controllers/sales_today_controller.js',
    'symfony/assets/controllers/sales_pipeline_controller.js',
    'symfony/assets/controllers/sales_deal_controller.js',
    'symfony/assets/controllers/sales_admin_pipeline_controller.js',
    'symfony/assets/controllers/sales_admin_rule_editor_controller.js',
    'symfony/assets/controllers/sales_admin_agent_controller.js',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, '/api/sales/'), 'Canonical Sales frontend restored legacy API: ' . $path);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), ['phtml', 'php'], true)) continue;
    $path = str_replace($root . '/', '', $file->getPathname());
    if (!str_contains($path, '/sales') && !str_contains((string) file_get_contents($file->getPathname()), 'data-sales-')) continue;
    $source = (string) file_get_contents($file->getPathname());
    $assert(!str_contains($source, '/api/sales/'), 'Wave 7 Sales view still references legacy Sales API: ' . $path);
    $assert(!str_contains($source, "'api/sales/"), 'Wave 7 Sales view still generates legacy Sales API URL: ' . $path);
}

$dealRuntime = $read('symfony/assets/controllers/sales_deal_controller.js');
foreach ([
    '/api/v1/sales/opportunities/',
    '/api/v1/sales/approvals/',
    '/api/v1/sales/actions/',
    '/communications',
] as $needle) {
    $assert(str_contains($dealRuntime, $needle), 'Canonical Deal runtime v1 dependency missing: ' . $needle);
}
$leadRuntime = $read('symfony/assets/controllers/sales_lead_controller.js');
foreach (['/api/v1/sales/leads/', '/opportunity', '/followups'] as $needle) {
    $assert(str_contains($leadRuntime, $needle), 'Canonical Lead runtime v1 dependency missing: ' . $needle);
}

$adminPipeline = $read('symfony/assets/controllers/sales_admin_pipeline_controller.js');
$adminAgent = $read('symfony/assets/controllers/sales_admin_agent_controller.js');
foreach (['/api/v1/sales/admin/pipelines/', '/stages', '/transitions'] as $needle) {
    $assert(str_contains($adminPipeline, $needle), 'Canonical admin Pipeline v1 dependency missing: ' . $needle);
}
$assert(str_contains($adminAgent, '/api/v1/sales/admin/agents/'), 'Canonical admin Agent v1 dependency missing.');

$rules = $read('symfony/assets/controllers/sales_admin_rule_editor_controller.js');
$assert(str_contains($rules, '/api/v1/sales/admin/rules/'), 'Canonical rule editor is not on Symfony v1.');

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_api_v1_sales_search:',
    'cos_api_v1_sales_opportunity_quick_update:',
    'cos_api_v1_sales_opportunity_meeting:',
    'cos_api_v1_sales_approval_approve:',
    'cos_api_v1_sales_action_execute:',
    'cos_api_v1_sales_admin_pipelines:',
    'cos_api_v1_sales_admin_rules:',
    'cos_api_v1_sales_admin_agents:',
    'cos_api_v1_sales_admin_policies:',
    'cos_api_v1_sales_admin_teams:',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Wave 7 Symfony route missing: ' . $needle);
}

foreach ([
    'symfony/src/Http/Api/V1/Controller/SalesFrontendController.php',
    'symfony/src/Http/Api/V1/Controller/SalesAdminFrontendController.php',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'PDO'), 'Wave 7 HTTP controller must not access PDO: ' . $path);
    $assert(!str_contains($source, 'Domains\\'), 'Wave 7 HTTP controller must not depend directly on Domains: ' . $path);
    foreach (['TenantContextProviderInterface', 'LegacySessionCsrfValidator', 'ActiveModuleResolver'] as $needle) {
        $assert(str_contains($source, $needle), 'Wave 7 HTTP boundary missing ' . $needle . ': ' . $path);
    }
}

$authorization = $read('symfony/src/Application/Sales/Admin/SalesAdminAuthorization.php');
foreach ([
    'sales.admin.pipeline.manage',
    'sales.admin.rules.manage',
    'sales.admin.agents.manage',
    'sales.admin.policies.manage',
    'sales.admin.teams.manage',
    'SalesAccessControlInterface',
] as $needle) {
    $assert(str_contains($authorization, $needle), 'Wave 7 admin capability boundary missing: ' . $needle);
}

$writeInterface = $read('app/Domains/Sales/Application/Contract/SalesWriteServiceInterface.php');
$writeService = $read('app/Domains/Sales/Application/Service/SalesWriteService.php');
$cases = $read('app/Domains/Sales/Application/Service/ClientCaseCommandService.php');
$assert(str_contains($writeInterface, 'quickUpdateOpportunity'), 'Wave 7 quick-update write port is missing.');
$assert(str_contains($writeService, 'quickUpdateOpportunity'), 'Wave 7 quick-update implementation is missing.');
$assert(str_contains($cases, '$requestedCorrelationId'), 'Wave 7 quick update does not preserve correlation.');
$writeFactory = $read('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesWriteServiceFactory.php');
$assert(str_contains($writeFactory, 'SalesAssignmentAuthorityInterface'), 'Wave 7 writer must receive Sales assignment authority.');
$assert(str_contains($writeFactory, '$this->assignmentAuthority'), 'Wave 7 owner assignment must preserve Sales authority.');

$services = $read('symfony/config/services.yaml');
foreach ([
    'SalesWorkspaceOperationalReadModelInterface',
    'SalesAssignmentAuthorityInterface',
    'MysqlSalesAssignmentAuthority',
    'SalesAccessControlInterface',
    'SalesPipelineAdministrationInterface',
    'SalesPipelineGovernanceInterface',
    'SalesRuleAdministrationInterface',
    'SalesAgentAdministrationInterface',
    'SalesPolicyAdministrationInterface',
    'SalesTeamAdministrationInterface',
] as $needle) {
    $assert(str_contains($services, $needle), 'Wave 7 composition root missing: ' . $needle);
}

echo "Symfony Sales Frontend Wave 7 cutover boundary OK\n";
