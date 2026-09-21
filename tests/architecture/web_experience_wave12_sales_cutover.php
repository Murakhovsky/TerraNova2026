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
$legacy = file_get_contents($root . '/symfony/src/Web/Sales/SalesPageController.php');
$services = file_get_contents($root . '/symfony/config/services.yaml');

foreach ([
    "cos_web_sales_dashboard:\n  path: /sales/dashboard\n  controller: App\\Web\\Sales\\SalesWorkspaceController::dashboard",
    "cos_web_sales_leads:\n  path: /sales/leads\n  controller: App\\Web\\Sales\\SalesWorkspaceController::leads",
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
expectSalesCutover(!str_contains($legacy, 'function dashboard('), 'Legacy SalesPageController dashboard action must be removed.');
expectSalesCutover(!str_contains($legacy, 'function leads('), 'Legacy SalesPageController leads action must be removed.');
expectSalesCutover(str_contains($services, 'App\\Web\\Sales\\SalesWorkspaceController:'), 'Production SalesWorkspaceController service wiring is missing.');

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

echo "Wave 12.26 Sales Cutover architecture gate passed.\n";
