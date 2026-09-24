<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-015 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Property/Query/GetPropertySubmissionsQueueQuery.php',
    'symfony/src/Application/Property/Query/GetPropertySubmissionsQueueQueryHandler.php',
    'symfony/src/Web/Property/PropertySubmissionsController.php',
    'symfony/src/Web/Property/PropertySubmissionsPresenter.php',
    'symfony/src/Web/Property/ViewModel/PropertySubmissionsViewModel.php',
    'symfony/templates/experience/property/submissions.html.twig',
] as $path)$read($path);

if(file_exists($root.'/app/Interfaces/Web/View/property/submissions.phtml'))throw new RuntimeException('VR-015 legacy submissions PHTML restored.');

$controller=$read('symfony/src/Web/Property/PropertySubmissionsController.php');
foreach(['GetPropertySubmissionsQueueQuery','PageArchetype::OperationalQueue','WorkspaceShellFactory',"'EntityList'","'KpiStrip'","'FilterBar'","'Pagination'"] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-015 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','PropertyWorkspaceReadModelInterface','Doctrine\\','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-015 controller leaked forbidden dependency: '.$forbidden);
}

$readModel=$read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyWorkspaceReadModel.php');
foreach(["public function submissions(","int \$offset = 0","LIMIT ' . \$limit . ' OFFSET ' . \$offset","'total' => \$total"] as $marker){
    if(!str_contains($readModel,$marker))throw new RuntimeException('VR-015 pagination contract incomplete: '.$marker);
}

$template=$read('symfony/templates/experience/property/submissions.html.twig');
foreach(['<twig:CosPageHeader','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:CosEntityListItem','data-property-submissions','data-cos-archetype'] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-015 Operational Queue composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-015 restored legacy/local presentation: '.$forbidden);
}

$routes=$read('symfony/config/routes.yaml');
if(!str_contains($routes,'PropertySubmissionsController::index'))throw new RuntimeException('VR-015 route cutover incomplete.');

echo "Wave 13 VR-015 Property Submissions Operational Queue passed.\n";
