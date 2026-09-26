<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
foreach([
 'symfony/src/Application/Administration/Query/GetAdministrationAnalyticsQueryHandler.php',
 'symfony/src/Web/Administration/AdministrationAnalyticsController.php',
 'symfony/src/Web/Administration/AdministrationAnalyticsPresenter.php',
 'symfony/src/Web/Administration/ViewModel/AdministrationAnalyticsViewModel.php',
 'symfony/templates/experience/administration/analytics.html.twig',
] as $file){
 if(!is_file($root.'/'.$file)) throw new RuntimeException('VR-022 artifact missing: '.$file);
}
foreach([
 'app/Interfaces/Web/View/admin/analytics.phtml',
 'frontend/entrypoints/analytics-workspace.js',
 'frontend/features/analytics/workspace.js',
 'frontend/features/analytics/workspace.css',
] as $legacy){
 if(file_exists($root.'/'.$legacy)) throw new RuntimeException('VR-022 legacy artifact restored: '.$legacy);
}
$controller=(string)file_get_contents($root.'/symfony/src/Web/Administration/AdministrationAnalyticsController.php');
foreach(['GetAdministrationAnalyticsQuery','PageArchetype::ExecutiveDashboard','WorkspaceShellFactory','PagePresentationFactory'] as $marker){
 if(!str_contains($controller,$marker)) throw new RuntimeException('VR-022 controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','PropertyFunnelAnalyticsInterface'] as $forbidden){
 if(str_contains($controller,$forbidden)) throw new RuntimeException('VR-022 Web controller leaked forbidden dependency: '.$forbidden);
}
$template=(string)file_get_contents($root.'/symfony/templates/experience/administration/analytics.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosFilterBar','class="cos-kpi-strip"','<twig:CosDataGrid','<twig:CosEntityListItem'] as $marker){
 if(!str_contains($template,$marker)) throw new RuntimeException('VR-022 composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
 if(str_contains($template,$forbidden)) throw new RuntimeException('VR-022 restored legacy/local presentation: '.$forbidden);
}
echo "Wave 13 VR-022 Administration Analytics passed.\n";
