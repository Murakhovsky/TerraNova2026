<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Data/DataGridColumn.php',
    'symfony/src/Web/Experience/Data/DataGridFilter.php',
    'symfony/src/Web/Experience/Data/DataGridQuery.php',
    'symfony/src/Web/Experience/Data/DataGridPage.php',
    'symfony/src/Web/Experience/Data/DataGridSavedView.php',
    'symfony/src/Web/Experience/Data/DataGridState.php',
    'symfony/src/Web/Experience/Data/DataGridUrlBuilder.php',
    'symfony/src/Web/Experience/Data/DataGridCsvExporter.php',
    'symfony/src/Web/Experience/Component/CosDataGrid.php',
    'symfony/templates/components/experience/cos_data_grid.html.twig',
    'symfony/assets/controllers/data_grid_controller.js',
    'symfony/assets/styles/data-grid.css',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.9 Data Platform file is missing: ' . $relative);
    }
}

foreach ([
    'DataGridColumn.php',
    'DataGridFilter.php',
    'DataGridQuery.php',
    'DataGridPage.php',
    'DataGridSavedView.php',
    'DataGridState.php',
    'DataGridUrlBuilder.php',
    'DataGridCsvExporter.php',
] as $file) {
    $source = (string) file_get_contents($root . '/symfony/src/Web/Experience/Data/' . $file);

    foreach (['Domains\\', 'Doctrine\\', 'Repository', 'EntityManager', 'PDO', '/api/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf('Data Platform model %s crossed platform boundary: %s', $file, $forbidden));
        }
    }
}

$query = (string) file_get_contents($root . '/symfony/src/Web/Experience/Data/DataGridQuery.php');
foreach ([
    "'q'",
    "'page'",
    "'per_page'",
    "'sort'",
    "'dir'",
    "'filter'",
    "'columns'",
    "'view'",
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('DataGrid URL state is missing: ' . $marker);
    }
}

$component = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosDataGrid.php');
foreach ([
    'DataGridQuery',
    'DataGridPage',
    'DataGridSavedView',
    'UIAction',
    'sortUrl',
    'savedViewUrl',
    'exportLink',
    'mobileColumns',
] as $marker) {
    if (!str_contains($component, $marker)) {
        throw new RuntimeException('CosDataGrid contract is missing: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'HttpClientInterface', '/api/'] as $forbidden) {
    if (str_contains($component, $forbidden)) {
        throw new RuntimeException('CosDataGrid crossed presentation boundary: ' . $forbidden);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_data_grid.html.twig');
foreach ([
    'method="get"',
    'aria-sort',
    'data-controller="data-grid"',
    'data-data-grid-target="selectAll"',
    'data-action="click->data-grid#bulkAction"',
    'data-action="click->data-grid#rowAction"',
    'cos-data-grid__mobile',
    'savedViewUrl',
    'Export CSV',
    'state.value',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('DataGrid template contract is missing: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/assets/controllers/data_grid_controller.js');
foreach ([
    'cos:datagrid-selection-change',
    'cos:datagrid-bulk-action',
    'cos:datagrid-row-action',
    'new Set',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('DataGrid browser behavior is missing: ' . $marker);
    }
}
foreach (['fetch(', 'axios', '/api/', 'localStorage', 'sessionStorage', 'Domains\\', 'Application\\'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('DataGrid controller contains forbidden data/business access: ' . $forbidden);
    }
}

$tabulator = (string) file_get_contents($root . '/symfony/assets/controllers/adapters/tabulator_controller.js');
foreach (['SAFE_OPTIONS', 'safeOptions()', "import { TabulatorFull as Tabulator } from 'tabulator-tables'"] as $marker) {
    if (!str_contains($tabulator, $marker)) {
        throw new RuntimeException('Advanced Tabulator adapter contract is missing: ' . $marker);
    }
}
foreach (['ajaxURL', 'ajaxRequestFunc', 'fetch(', 'axios', '/api/'] as $forbidden) {
    if (str_contains($tabulator, $forbidden)) {
        throw new RuntimeException('Tabulator adapter may not own server transport: ' . $forbidden);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/data-grid.css');
foreach ([
    '.cos-data-grid__table',
    '.cos-data-grid__bulk',
    '.cos-data-grid__saved-views',
    '.cos-data-grid__mobile',
    '.cos-data-grid__pagination',
    '@media (max-width: 760px)',
] as $marker) {
    if (!str_contains($styles, $marker)) {
        throw new RuntimeException('DataGrid style contract is missing: ' . $marker);
    }
}
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styles) === 1 || str_contains($styles, '--tn-')) {
    throw new RuntimeException('DataGrid styles must use canonical COS semantic tokens.');
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($appCss, "@import './data-grid.css';")) {
    throw new RuntimeException('DataGrid styles are not loaded by canonical AssetMapper CSS.');
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'Data Platform / DataGrid',
    '<twig:CosDataGrid',
    'dataGridDemo.savedViews',
    'exportUrl="/dev/ui/data-export"',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('Data Platform catalog marker is missing: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_design_system_data_export:', 'path: /dev/ui/data-export'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Data Platform reference export route is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/DataPlatformSmokeCommand.php');
foreach (['cos:web:data:smoke', 'DataGridCatalogDemo', 'DataGridCsvExporter'] as $marker) {
    if (!str_contains($smoke, $marker)) {
        throw new RuntimeException('Data Platform runtime smoke contract is missing: ' . $marker);
    }
}

echo "Wave 12.9 Data Platform passed.\n";
