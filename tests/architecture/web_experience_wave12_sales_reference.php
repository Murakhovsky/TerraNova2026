<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function expectSalesReference(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = file_get_contents($root . '/symfony/src/Web/Sales/SalesReferenceController.php');
$routes = file_get_contents($root . '/symfony/config/routes.yaml');
$services = file_get_contents($root . '/symfony/config/services.yaml');
$provider = file_get_contents($root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');

foreach ([
    'QueryBusInterface',
    'GetSalesDashboardQuery',
    'ListSalesLeadsQuery',
    'GetSalesLeadQuery',
    'ProviderBackedShellNavigation',
    'WorkspaceCompositionResolver',
    "new EntityRef('sales.lead'",
    "'sales.lead'",
] as $needle) {
    expectSalesReference(str_contains($controller, $needle), 'Sales reference controller missing canonical dependency: ' . $needle);
}

foreach ([
    'PhtmlRenderer',
    'SalesWorkspaceReadModelInterface',
    'SalesWorkspaceOperationalReadModelInterface',
    'PDO',
] as $forbidden) {
    expectSalesReference(!str_contains($controller, $forbidden), 'Sales reference controller must not depend on legacy/direct read boundary: ' . $forbidden);
}

foreach ([
    'path: /sales/reference/dashboard',
    'path: /sales/reference/leads',
    'path: /sales/reference/leads/{id}',
] as $route) {
    expectSalesReference(str_contains($routes, $route), 'Sales reference route missing: ' . $route);
}

expectSalesReference(str_contains($services, 'App\\Web\\Sales\\SalesReferenceController:'), 'Sales reference controller service is missing.');
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
    'symfony/templates/experience/sales/reference_dashboard.html.twig',
    'symfony/templates/experience/sales/reference_leads.html.twig',
    'symfony/templates/experience/sales/reference_lead_workspace.html.twig',
] as $template) {
    expectSalesReference(is_file($root . '/' . $template), 'Sales reference template missing: ' . $template);
}

$leadWorkspace = file_get_contents($root . '/symfony/templates/experience/sales/reference_lead_workspace.html.twig');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosWorkspace'), 'Lead Workspace must use canonical CosWorkspace composition.');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosEntityHeader'), 'Lead Workspace must use canonical entity header.');
expectSalesReference(str_contains($leadWorkspace, '<twig:CosNextAction'), 'Lead Workspace must use canonical next-action primitive.');

$dashboard = file_get_contents($root . '/symfony/templates/experience/sales/reference_dashboard.html.twig');
expectSalesReference(str_contains($dashboard, '<twig:CosMoneyMetric'), 'Sales Dashboard must use canonical money metric.');
expectSalesReference(str_contains($dashboard, '<twig:CosTrendMetric'), 'Sales Dashboard must use canonical trend metric.');

$leads = file_get_contents($root . '/symfony/templates/experience/sales/reference_leads.html.twig');
expectSalesReference(str_contains($leads, '<twig:CosFilterBar'), 'Lead List must use canonical filter bar.');
expectSalesReference(str_contains($leads, '<twig:CosEntityListItem'), 'Lead List must use canonical entity list items.');

echo "Wave 12.25 Sales Reference Implementation architecture gate passed.\n";
