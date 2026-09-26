<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$controller=$read('symfony/src/Web/Administration/AdministrationAnalyticsController.php');
foreach(['GetAdministrationAnalyticsQuery','PageArchetype::ExecutiveDashboard','WorkspaceShellFactory','AdministrationAnalyticsPresenter'] as $marker){
    $assert(str_contains($controller,$marker),'Analytics controller missing canonical contract: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','PropertyFunnelAnalyticsInterface'] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Analytics Web controller leaked retired/direct read dependency: '.$forbidden);
}

$handler=$read('symfony/src/Application/Administration/Query/GetAdministrationAnalyticsQueryHandler.php');
foreach(['PropertyFunnelAnalyticsInterface','$this->analytics->report('] as $marker){
    $assert(str_contains($handler,$marker),'Analytics Application Query missing: '.$marker);
}

$view=$read('symfony/templates/experience/administration/analytics.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosFilterBar','class="cos-kpi-strip"','<twig:CosDataGrid','<twig:CosEntityListItem'] as $marker){
    $assert(str_contains($view,$marker),'Analytics Twig missing: '.$marker);
}
foreach(['tn-','style=','<script'] as $forbidden){
    $assert(!str_contains($view,$forbidden),'Analytics Twig restored legacy presentation: '.$forbidden);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /admin/analytics','AdministrationAnalyticsController::index'] as $marker){
    $assert(str_contains($routes,$marker),'Analytics route missing: '.$marker);
}

foreach([
    'app/Interfaces/Web/View/admin/analytics.phtml',
    'frontend/entrypoints/analytics-workspace.js',
    'frontend/features/analytics/workspace.js',
    'frontend/features/analytics/workspace.css',
] as $legacy){
    $assert(!file_exists($root.'/'.$legacy),'Retired Analytics artifact restored: '.$legacy);
}

echo "WEB V0.8/Wave 13 Analytics workspace passed.\n";
