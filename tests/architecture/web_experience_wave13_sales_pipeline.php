<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Sales/Query/GetSalesPipelineWorkspaceQuery.php',
    'symfony/src/Application/Sales/Query/GetSalesPipelineWorkspaceQueryHandler.php',
    'symfony/src/Web/Sales/SalesPipelineController.php',
    'symfony/src/Web/Sales/SalesPipelinePresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesPipelineViewModel.php',
    'symfony/src/Web/Sales/Component/SalesPipelineBoard.php',
    'symfony/templates/experience/sales/pipeline.html.twig',
    'symfony/templates/components/sales/sales_pipeline_board.html.twig',
    'symfony/assets/controllers/sales_pipeline_controller.js',
    'symfony/assets/styles/domains/sales.css',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-006 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/sales/pipeline.phtml')) {
    throw new RuntimeException('VR-006 must delete legacy Sales Pipeline PHTML ownership.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /sales/pipeline', 'SalesPipelineController::index'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-006 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesPipelineController.php');
foreach ([
    'GetSalesPipelineWorkspaceQuery',
    'PageArchetype::ProcessPipeline',
    'PagePresentationFactory',
    'WorkspaceShellFactory',
    "'Toolbar'",
    "'FilterBar'",
    'SalesPipelinePresenter',
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-006 controller contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/pipeline.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosFilterBar',
    '<twig:SalesPipelineBoard',
    'data-controller="sales-pipeline"',
    'data-sales-pipeline-root',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-006 Process/Pipeline composition is incomplete: ' . $marker);
    }
}

$board = (string) file_get_contents($root . '/symfony/templates/components/sales/sales_pipeline_board.html.twig');
foreach ([
    'data-sales-stage-dropzone',
    'data-sales-deal-card',
    'draggable="true"',
    'submit->sales-pipeline#changeStage',
    'name="stage_id"',
] as $marker) {
    if (!str_contains($board, $marker)) {
        throw new RuntimeException('VR-006 SalesPipelineBoard interaction contract is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden) || str_contains($board, $forbidden)) {
        throw new RuntimeException('VR-006 restored legacy/local presentation: ' . $forbidden);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/sales_pipeline_controller.js');
foreach ([
    'dragStart',
    'dragEnd',
    'dragOver',
    'drop(event)',
    'changeStage',
    '/api/v1/sales/opportunities/',
    '/stage',
    'concurrent_stage_change',
    'X-CSRF-Token',
    'X-Idempotency-Key',
] as $marker) {
    if (!str_contains($stimulus, $marker)) {
        throw new RuntimeException('VR-006 Pipeline Stimulus contract is incomplete: ' . $marker);
    }
}

foreach ([
    'symfony/src/Web/Sales/SalesPageController.php',
    'frontend/features/sales/workspace.js',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('VR-006 retired legacy Pipeline owner returned: ' . $retired);
    }
}

echo "Wave 13 VR-006 /sales/pipeline Process Pipeline passed.\n";
