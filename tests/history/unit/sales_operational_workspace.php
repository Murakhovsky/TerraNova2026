<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$pipeline = (string) file_get_contents($root . '/symfony/templates/experience/sales/pipeline.html.twig');
$pipelineController = (string) file_get_contents($root . '/symfony/assets/controllers/sales_pipeline_controller.js');
$pipelineStyles = (string) file_get_contents($root . '/symfony/assets/styles/domains/sales.css');
$today = (string) file_get_contents($root . '/symfony/templates/experience/sales/today.html.twig');
$todayPresenter = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesTodayPresenter.php');
$deal = (string) file_get_contents($root . '/symfony/templates/experience/sales/deal_workspace.html.twig');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');

foreach (['data-sales-pipeline-root', 'data-sales-stage-dropzone', 'data-sales-deal-card', 'draggable="true"'] as $marker) {
    $assert(str_contains($pipeline, $marker), 'Pipeline workspace is missing operational marker: ' . $marker);
}
$assert(str_contains($pipeline, 'data-csrf='), 'Pipeline workspace must expose CSRF for canonical stage mutations.');
$assert(str_contains($routes, '/api/v1/sales/opportunities/{id}/stage'), 'Canonical Symfony Sales stage endpoint is missing.');
foreach (['dragStart', 'drop(event)', 'changeStage', 'concurrent_stage_change', '/stage'] as $marker) {
    $assert(str_contains($pipelineController, $marker), 'Canonical Pipeline Stimulus controller is missing interaction: ' . $marker);
}

// Only anchors actually used by Today belong to this contract. Timeline remains a valid
// Deal section, but New Replies now deep-link to Communications where the manager can answer.
foreach (['work', 'intelligence', 'communications'] as $anchor) {
    $assert(str_contains($todayPresenter, "'anchor' => '" . $anchor . "'"), 'Today presenter is missing Deal Workspace mapping: ' . $anchor);
    $assert(str_contains($deal, 'id="' . $anchor . '"'), 'Deal Workspace is missing mapped section: ' . $anchor);
}
$assert(str_contains($todayPresenter, "'/sales/deals/' . \$dealId . '#' . \$definition['anchor']"), 'Today presenter must compose canonical Deal Workspace deep links.');
foreach (['is-drop-target', 'is-dragging', '.cos-sales-pipeline-card__stage-form'] as $marker) {
    $assert(str_contains($pipelineStyles, $marker), 'Canonical Sales domain CSS is missing Pipeline state: ' . $marker);
}

echo "Sales operational workspace passed: Pipeline mutation and Today deep links use canonical Deal Workspace flows.\n";
