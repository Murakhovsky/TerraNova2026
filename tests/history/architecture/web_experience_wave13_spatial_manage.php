<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-018 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Spatial/Query/GetSpatialManageQuery.php',
    'symfony/src/Application/Spatial/Query/GetSpatialManageQueryHandler.php',
    'symfony/src/Web/Spatial/SpatialManageController.php',
    'symfony/src/Web/Spatial/SpatialManagePresenter.php',
    'symfony/src/Web/Spatial/ViewModel/SpatialManageViewModel.php',
    'symfony/templates/experience/spatial/manage.html.twig',
] as $path)$read($path);

if(file_exists($root.'/app/Interfaces/Web/View/spatial/manage.phtml')){
    throw new RuntimeException('VR-018 legacy Spatial manage PHTML restored.');
}

$controller=$read('symfony/src/Web/Spatial/SpatialManageController.php');
foreach([
    'GetSpatialManageQuery',
    'PageArchetype::MapSpatial',
    'WorkspaceShellFactory',
    "'PageHeader'",
    "'Toolbar'",
    "'ContextPanel'",
    "'KpiStrip'",
    "'FilterBar'",
    "'EntityList'",
] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-018 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','SpatialSceneInterface','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-018 controller leaked forbidden dependency: '.$forbidden);
}

$handler=$read('symfony/src/Application/Spatial/Query/GetSpatialManageQueryHandler.php');
foreach(['SpatialSceneInterface','managerScenes(','stats()'] as $marker){
    if(!str_contains($handler,$marker))throw new RuntimeException('VR-018 Application read composition incomplete: '.$marker);
}
if(str_contains($handler,'App\\Web\\'))throw new RuntimeException('VR-018 Application query leaked Web dependency.');

$template=$read('symfony/templates/experience/spatial/manage.html.twig');
foreach([
    '<twig:CosPageHeader',
    'class="cos-kpi-strip"',
    '<twig:CosToolbar',
    '<twig:CosFilterBar',
    '<twig:CosEntityListItem',
    '<twig:CosContextPanel',
    'data-spatial-manage',
    'data-cos-archetype',
] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-018 Map / Spatial composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-018 restored legacy/local presentation: '.$forbidden);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /spatial/manage','SpatialManageController::index','SpatialPageController::edit','SpatialPageController::scene'] as $marker){
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-018 Spatial route ownership incomplete: '.$marker);
}

$legacy=$read('symfony/src/Web/Spatial/SpatialPageController.php');
if(str_contains($legacy,'public function manage('))throw new RuntimeException('VR-018 left duplicate Spatial manage ownership.');
foreach(['public function edit(','public function upload(','public function publish(','public function scene('] as $retained){
    if(!str_contains($legacy,$retained))throw new RuntimeException('VR-018 accidentally removed retained Spatial scenario: '.$retained);
}

echo "Wave 13 VR-018 Spatial Manage Map / Spatial surface passed.\n";
