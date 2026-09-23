<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Sales/Query/GetSalesDealsCollectionQuery.php',
    'symfony/src/Application/Sales/Query/GetSalesDealsCollectionQueryHandler.php',
    'symfony/src/Web/Sales/SalesDealsController.php',
    'symfony/src/Web/Sales/SalesDealsPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesDealsViewModel.php',
    'symfony/templates/experience/sales/deals.html.twig',
    'symfony/assets/controllers/sales_deals_controller.js',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-007 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/sales/deals.phtml')) {
    throw new RuntimeException('VR-007 must delete legacy Sales Deals PHTML ownership.');
}

$filter = (string) file_get_contents($root . '/symfony/src/Web/Experience/Data/DataGridFilter.php');
foreach (["['select', 'text']", "DataGrid filter type must be select or text"] as $marker) {
    if (!str_contains($filter, $marker)) {
        throw new RuntimeException('VR-007 generic DataGrid text-filter contract is incomplete: ' . $marker);
    }
}

$grid = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_data_grid.html.twig');
foreach (["filter.type == 'text'", 'type="text"', 'filter.placeholder'] as $marker) {
    if (!str_contains($grid, $marker)) {
        throw new RuntimeException('VR-007 DataGrid template does not render text filters: ' . $marker);
    }
}

$readModel = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceOperationalReadModel.php');
foreach (["' OFFSET ' . \$this->offset", 'private function offset('] as $marker) {
    if (!str_contains($readModel, $marker)) {
        throw new RuntimeException('VR-007 operational pagination contract is incomplete: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/deals', 'SalesDealsController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-007 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesDealsController.php');
foreach ([
    'GetSalesDealsCollectionQuery',
    'DataGridQuery',
    'PageArchetype::Collection',
    "'Toolbar'",
    "'DataGrid'",
    'SalesDealsPresenter',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-007 controller contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/deals.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosDataGrid',
    'data-controller="sales-deals"',
    'cos:datagrid-row-action->sales-deals#rowAction',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-007 Collection composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-007 restored legacy/local Deals presentation: ' . $forbidden);
    }
}

if (is_file($root . '/symfony/src/Web/Sales/SalesPageController.php')) {
    throw new RuntimeException('VR-007 retired SalesPageController returned after final Sales cutover.');
}

echo "Wave 13 VR-007 /sales/deals Collection DataGrid passed.\n";
