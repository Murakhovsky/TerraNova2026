<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$legacyArtifacts = [
    'app/Interfaces/Api/Controller/SalesController.php',
    'app/Interfaces/Api/Controller/SalesWorkspaceSearchController.php',
    'app/Interfaces/Api/Controller/SalesWorkspaceActionsController.php',
    'app/Interfaces/Api/Controller/SalesDealLifecycleController.php',
    'app/Interfaces/Api/Controller/SalesAdminPipelineController.php',
    'app/Interfaces/Api/Controller/SalesAdminRuleController.php',
    'app/Interfaces/Api/Controller/SalesAdminAgentController.php',
    'app/Interfaces/Api/Controller/SalesAdminPolicyController.php',
    'app/Interfaces/Api/Controller/SalesAdminTeamController.php',
    'app/Interfaces/Api/Controller/SalesAdminIntegrationController.php',
    'app/Interfaces/Api/Controller/SalesAdminHealthController.php',
    'app/Interfaces/Api/Controller/SalesDirectorController.php',
    'app/Interfaces/Api/Controller/CrmWebhookController.php',
    'app/Interfaces/Web/Controller/SalesController.php',
    'app/Interfaces/Web/Controller/SalesAdminController.php',
    'app/Interfaces/Web/Controller/SalesAdminTeamController.php',
    'app/Interfaces/Web/Controller/SalesAdminAgentController.php',
    'app/Interfaces/Web/Controller/SalesAdminPolicyController.php',
    'app/Interfaces/Web/Controller/SalesAdminHealthController.php',
    'app/Interfaces/Web/Controller/SalesAdminIntegrationController.php',
    'app/Interfaces/Web/Routing/SalesDirectorRoutes.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Web/Routing/SalesTeamRoutes.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesAdministrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesModuleRouteContributor.php',
];

foreach ($legacyArtifacts as $path) {
    $assert(!is_file($root . '/' . $path), 'Retired legacy Sales artifact restored: ' . $path);
}

$frontendRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach (['/api/sales/', '/api/integrations/crm/', '/api/integrations/{organization:', '/sales/dashboard', '/sales/admin'] as $legacy) {
    $assert(!str_contains($frontendRoutes, $legacy), 'Retired Sales route restored in Phalcon FrontendRoutes: ' . $legacy);
}

$salesManifest = $read('app/Domains/Sales/module.php');
$assert(!str_contains($salesManifest, "'salesRouteContributor'"), 'Sales manifest restored its retired Phalcon route contribution.');

$symfonyRoutes = $read('symfony/config/routes.yaml');
foreach ([
    '/api/v1/sales/dashboard',
    '/api/v1/sales/leads',
    '/api/v1/sales/opportunities',
    '/api/v1/sales/search',
    '/api/v1/sales/admin/pipelines',
    '/api/v1/sales/admin/rules',
    '/api/v1/sales/admin/teams',
    '/api/v1/sales/integrations',
    '/api/v1/integrations/crm/{id}/webhook',
    'cos_web_sales_root:',
    'cos_web_sales_admin:',
    'cos_web_sales_admin_pipelines_page:',
    'cos_web_sales_admin_rules_page:',
    'cos_web_sales_admin_agents_page:',
    'cos_web_sales_admin_actions_page:',
    'cos_web_sales_admin_teams_page:',
    'cos_web_sales_admin_integrations_page:',
    'cos_web_sales_admin_health_page:',
] as $route) {
    $assert(str_contains($symfonyRoutes, $route), 'Canonical Symfony Sales route missing after legacy retirement: ' . $route);
}

foreach ([
    'frontend/features/sales/workspace.js',
    'frontend/features/sales/admin.js',
    'frontend/features/sales/rule-editor.js',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, '/api/sales/'), 'Live Sales frontend restored a legacy API dependency: ' . $path);
}

echo "Sales legacy API + SSR retirement boundary passed.\n";
