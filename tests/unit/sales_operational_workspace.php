<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$pipeline = (string) file_get_contents($root . '/app/Interfaces/Web/View/sales/pipeline.phtml');
$today = (string) file_get_contents($root . '/app/Interfaces/Web/View/sales/today.phtml');
$js = (string) file_get_contents($root . '/frontend/features/sales/workspace.js');
$css = (string) file_get_contents($root . '/frontend/features/sales/workspace.css');
$routes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');

foreach (['data-sales-pipeline-root', 'data-sales-stage-dropzone', 'data-sales-deal-card', 'draggable="true"'] as $marker) {
    $assert(str_contains($pipeline, $marker), 'Pipeline workspace is missing operational marker: ' . $marker);
}
$assert(str_contains($pipeline, 'data-csrf='), 'Pipeline workspace must expose CSRF for canonical stage mutations.');
$assert(str_contains($routes, "'/api/sales/deals/{id:[0-9]+}/stage'"), 'Canonical Sales stage endpoint is missing.');
foreach (['initSalesPipeline', 'postStageChange', 'is-drop-target', '/stage'] as $marker) {
    $assert(str_contains($js, $marker), 'Sales workspace JS is missing Pipeline interaction: ' . $marker);
}
foreach (['#work', '#intelligence', '#timeline'] as $anchor) {
    $assert(str_contains($today, $anchor), 'Today workspace must deep-link operational items to Deal Workspace section ' . $anchor);
}
foreach (['is-drop-target', 'is-dragging'] as $marker) {
    $assert(str_contains($css, $marker), 'Sales workspace CSS is missing drag/drop state: ' . $marker);
}

echo "Sales operational workspace passed: Pipeline stage mutation and Today deep links use canonical Deal Workspace flows.\n";
