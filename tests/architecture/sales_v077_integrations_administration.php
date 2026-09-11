<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Domains/Sales/Application/Contract/SalesIntegrationAdministrationInterface.php',
    'app/Domains/Sales/Application/Contract/SalesIntegrationHealthProbeInterface.php',
    'app/Domains/Sales/Automation/Integration/SalesIntegrationDefinitionCatalog.php',
    'app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesIntegrationAdministration.php',
    'app/Infrastructure/Integration/Crm/CrmRegistrySalesIntegrationHealthProbe.php',
    'app/Interfaces/Api/Controller/SalesAdminIntegrationController.php',
    'app/Interfaces/Web/Controller/SalesAdminIntegrationController.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Web/View/sales_admin/integrations.phtml',
    'app/migrations/20260911_000035_sales_v077_integrations_administration.sql',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing V0.7.7 artifact: ' . $path);
    }
}

$service = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesIntegrationAdministration.php');
foreach (['cos_integrations', 'sales_integration_routes', 'configuration_version', 'health_status', 'credentials_configured', 'INTEGRATION_ROUTE'] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('V0.7.7 integration administration contract missing: ' . $needle);
    }
}
if (str_contains($service, "'credentials_reference' => $credentialsReference")) {
    throw new RuntimeException('Credential reference must not be copied into configuration revisions.');
}

$migration = file_get_contents($root . '/app/migrations/20260911_000035_sales_v077_integrations_administration.sql');
foreach (['sales_integration_routes', "ENUM('DRAFT','ACTIVE','DISABLED','ARCHIVED')", 'health_status', 'sales.admin.integrations.manage'] as $needle) {
    if (!str_contains($migration, $needle)) {
        throw new RuntimeException('V0.7.7 migration contract missing: ' . $needle);
    }
}

$applicationFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Domains/Sales/Application'));
foreach ($applicationFiles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = file_get_contents($file->getPathname());
    foreach (['Telegram\\', 'Meta\\', 'Google\\', 'N8n\\', 'Infrastructure\\Integration\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('Provider/infrastructure dependency leaked into Sales Application: ' . $file->getPathname());
        }
    }
}

echo "Sales V0.7.7 architecture contract OK\n";
