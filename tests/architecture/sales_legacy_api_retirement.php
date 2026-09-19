<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$legacyControllers = [
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
    'app/Interfaces/Web/Routing/SalesDirectorRoutes.php',
];

foreach ($legacyControllers as $path) {
    $assert(!is_file($root . '/' . $path), 'Retired legacy Sales API artifact restored: ' . $path);
}

foreach ([
    'app/Interfaces/Web/Routing/FrontendRoutes.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Web/Routing/SalesTeamRoutes.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesAdministrationRoutes.php',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, '/api/sales/'), 'Legacy /api/sales/* route restored in ' . $path);
    $assert(!str_contains($source, '/api/integrations/crm/'), 'Legacy integration webhook restored in ' . $path);
    $assert(!str_contains($source, '/api/integrations/{organization:'), 'Legacy provider webhook restored in ' . $path);
}

$contributor = $read('app/Interfaces/Web/Routing/SalesModuleRouteContributor.php');
$assert(!str_contains($contributor, 'SalesDirectorRoutes::register'), 'Retired SalesDirectorRoutes contribution restored.');

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

echo "Sales legacy API retirement boundary passed.\n";
