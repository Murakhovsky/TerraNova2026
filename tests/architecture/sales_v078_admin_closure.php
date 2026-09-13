<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'app/Domains/Sales/Application/Contract/SalesAdministrationReadModelInterface.php',
    'app/Domains/Sales/Application/Contract/CrmIngressResolverInterface.php',
    'app/Domains/Sales/Application/Service/SalesAdministrationHealthClassifier.php',
    'app/Infrastructure/Platform/ReadModel/MySql/MysqlSalesAdministrationReadModel.php',
    'app/Infrastructure/Integration/Crm/MysqlCrmIngressResolver.php',
    'app/Bootstrap/SalesAdministrationServices.php',
    'app/Bootstrap/SalesIntegrationServices.php',
    'app/Interfaces/Api/Controller/SalesAdminHealthController.php',
    'app/Interfaces/Api/Controller/CrmWebhookController.php',
    'app/Interfaces/Web/Controller/SalesAdminHealthController.php',
    'app/Interfaces/Web/Routing/SalesAdministrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesModuleRouteContributor.php',
    'app/Interfaces/Web/View/sales_admin/health.phtml',
    'app/migrations/20260911_000036_sales_v078_admin_closure.sql',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) throw new RuntimeException('Missing V0.7.8 artifact: ' . $path);
}

$readModel = (string) file_get_contents($root . '/app/Infrastructure/Platform/ReadModel/MySql/MysqlSalesAdministrationReadModel.php');
foreach (['cos_integrations','cos_jobs','cos_actions','cos_approvals','cos_agent_runs','cos_crm_inbox','cos_audit_log','cos_configuration_revisions','cos_operational_metrics'] as $table) {
    if (!str_contains($readModel, $table)) throw new RuntimeException('V0.7.8 read model missing source: ' . $table);
}
foreach (['credentials_reference','before_payload','after_payload','snapshot_json','SELECT *'] as $forbidden) {
    if (str_contains($readModel, $forbidden)) throw new RuntimeException('V0.7.8 read model exposes sensitive/raw data: ' . $forbidden);
}
if (preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $readModel)) {
    throw new RuntimeException('V0.7.8 administration read model must remain read-only.');
}

$migration = (string) file_get_contents($root . '/app/migrations/20260911_000036_sales_v078_admin_closure.sql');
foreach (['INTEGRATION_ROUTE','ACTIVATE','sales.admin.audit.view'] as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('V0.7.8 closure migration missing: ' . $needle);
}

$healthRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesAdministrationRoutes.php');
foreach (['/sales/admin/health','/api/sales/admin/health'] as $needle) {
    if (!str_contains($healthRoutes, $needle)) throw new RuntimeException('V0.7.8 health route missing: ' . $needle);
}

$integrationRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesIntegrationRoutes.php');
foreach (['/api/integrations/crm/{integration:', 'receiveIntegration'] as $needle) {
    if (!str_contains($integrationRoutes, $needle)) throw new RuntimeException('V0.7.8 canonical CRM ingress missing: ' . $needle);
}

$resolver = (string) file_get_contents($root . '/app/Infrastructure/Integration/Crm/MysqlCrmIngressResolver.php');
foreach (['cos_integrations','capability','CRM','status','ACTIVE'] as $needle) {
    if (!str_contains($resolver, $needle)) throw new RuntimeException('V0.7.8 ingress resolver missing active integration guard: ' . $needle);
}
foreach (['credentials_reference','SELECT *'] as $forbidden) {
    if (str_contains($resolver, $forbidden)) throw new RuntimeException('V0.7.8 ingress resolver crossed credentials boundary: ' . $forbidden);
}

$api = (string) file_get_contents($root . '/app/Interfaces/Api/Controller/SalesAdminHealthController.php');
$web = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/SalesAdminHealthController.php');
if (!str_contains($api, 'SalesCapability::AdminAuditView') || !str_contains($web, 'SalesCapability::AdminAuditView')) {
    throw new RuntimeException('V0.7.8 health/audit access must use sales.admin.audit.view.');
}

$contributor = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/SalesModuleRouteContributor.php');
foreach (['SalesIntegrationRoutes::register','SalesAdministrationRoutes::register'] as $needle) {
    if (!str_contains($contributor, $needle)) throw new RuntimeException('Sales route contributor missing: ' . $needle);
}

$services = (string) file_get_contents($root . '/app/config/services_kernel.php');
foreach (['SalesIntegrationServices.php','SalesAdministrationServices.php'] as $needle) {
    if (!str_contains($services, $needle)) throw new RuntimeException('V0.7.8 services not registered: ' . $needle);
}

$operations = (string) file_get_contents($root . '/app/Infrastructure/Platform/ReadModel/MySql/MysqlOperationsReadModel.php');
$start = strpos($operations, "'integrations' =>");
$end = strpos($operations, "'actions' =>", $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('Cannot verify operations integration projection.');
$projection = substr($operations, $start, $end - $start);
foreach (['credentials_reference',' configuration, ',' type, name, status'] as $forbidden) {
    if (str_contains($projection, $forbidden)) throw new RuntimeException('Operations integration projection exposes obsolete/sensitive field: ' . $forbidden);
}
foreach (['integration_key','configuration_version','health_status'] as $field) {
    if (!str_contains($projection, $field)) throw new RuntimeException('Operations integration projection missing field: ' . $field);
}

echo "Sales V0.7.8 audit/health/admin closure architecture: OK\n";
