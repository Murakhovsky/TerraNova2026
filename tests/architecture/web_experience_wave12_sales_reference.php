<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function expectSalesReference(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = file_get_contents($root . '/symfony/src/Web/Sales/SalesWorkspaceController.php');
$dashboardController = file_get_contents($root . '/symfony/src/Web/Sales/SalesDashboardController.php');
$leadsController = file_get_contents($root . '/symfony/src/Web/Sales/SalesLeadsController.php');
$routes = file_get_contents($root . '/symfony/config/routes.yaml');
$services = file_get_contents($root . '/symfony/config/services.yaml');
$provider = file_get_contents($root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');

foreach ([
    'QueryBusInterface',
    'GetSalesLeadQuery',
    'ProviderBackedShellNavigation',
    'WorkspaceCompositionResolver',
    "new EntityRef('sales.lead'",
    "'sales.lead'",
] as $needle) {
    expectSalesReference(str_contains($controller, $needle), 'Sales production controller missing canonical dependency: ' . $needle);
}

foreach ([
    'QueryBusInterface',
    'GetSalesDashboardQuery',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    'PageArchetype::DomainDashboard',
] as $needle) {
    expectSalesReference(str_contains($dashboardController, $needle), 'Sales Dashboard controller missing canonical dependency: ' . $needle);
}

foreach ([
    'QueryBusInterface',
    'ListSalesLeadsQuery',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    'PageArchetype::Collection',
] as $needle) {
    expectSalesReference(str_contains($leadsController, $needle), 'Sales Leads controller missing canonical dependency: ' . $needle);
}

foreach ([
    'PhtmlRenderer',
    'SalesWorkspaceReadModelInterface',
    'SalesWorkspaceOperationalReadModelInterface',
    'PDO',
] as $forbidden) {
    expectSalesReference(!str_contains($controller, $forbidden), 'Sales reference controller must not depend on legacy/direct read boundary: ' . $forbidden);
    expectSalesReference(!str_contains($dashboardController, $forbidden), 'Sales Dashboard controller must not depend on legacy/direct read boundary: ' . $forbidden);
    expectSalesReference(!str_contains($leadsController, $forbidden), 'Sales Leads controller must not depend on legacy/direct read boundary: ' . $forbidden);
}

foreach ([
    'path: /sales/dashboard',
    'path: /sales/leads',
    'path: /sales/leads/{id}',
] as $route) {
    expectSalesReference(str_contains($routes, $route), 'Sales production route missing: ' . $route);
}

expectSalesReference(str_contains($services, 'App\\Web\\Sales\\SalesWorkspaceController:'), 'Sales reference controller service is missing.');
expectSalesReference(str_contains($services, 'App\\Web\\Sales\\SalesDashboardController:'), 'Sales Dashboard controller service is missing.');
expectSalesReference(str_contains($services, 'App\\Web\\Sales\\SalesLeadsController:'), 'Sales Leads controller service is missing.');
expectSalesReference(str_contains($services, "tags: ['controller.service_arguments']"), 'Sales reference controller must be a Symfony controller service.');

foreach ([
    'NavigationProviderInterface',
    'SearchProviderInterface',
    'CommandProviderInterface',
    'WorkspaceProviderInterface',
    'WorkspaceExtensionProviderInterface',
    'ActionProviderInterface',
] as $capability) {
    expectSalesReference(str_contains($provider, $capability), 'Sales reference vertical must expose platform capability: ' . $capability);
}

foreach ([
    'symfony/templates/experience/sales/dashboard.html.twig',
    'symfony/templates/experience/sales/leads.html.twig',
    'symfony/templates/experience/sales/lead_workspace.html.twig',
] as $template) {
    expectSalesReference(is_file($root . '/' . $template), 'Sales production template missing: ' . $template);
}

$leadWorkspace = file_get_contents($root . '/symfony/templates/experience/sales/lead_workspace.html.twig');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosWorkspace'), 'Lead Workspace must use canonical CosWorkspace composition.');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosEntityHeader'), 'Lead Workspace must use canonical entity header.');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosNextAction'), 'Lead Workspace must use canonical next-action primitive.');

$dashboard = file_get_contents($root . '/symfony/templates/experience/sales/dashboard.html.twig');
expectSalesReference(str_contains($dashboard, '<twig:CosPageHeader'), 'Sales Dashboard must use canonical page header.');
expectSalesReference(str_contains($dashboard, 'class="cos-kpi-strip"'), 'Sales Dashboard must use canonical KPI strip.');
expectSalesReference(str_contains($dashboard, '<twig:CosMoneyMetric'), 'Sales Dashboard must use canonical money metric.');
expectSalesReference(str_contains($dashboard, '<twig:CosTrendMetric'), 'Sales Dashboard must use canonical trend metric.');

$leads = file_get_contents($root . '/symfony/templates/experience/sales/leads.html.twig');
expectSalesReference(str_contains($leads, '<twig:CosPageHeader'), 'Lead List must use canonical page header.');
expectSalesReference(str_contains($leads, '<twig:CosFilterBar'), 'Lead List must use canonical filter bar.');
expectSalesReference(str_contains($leads, '<twig:CosEntityListItem'), 'Lead List must use canonical entity list items.');

echo "Wave 12.25 Sales Reference Implementation architecture gate passed.\n";
