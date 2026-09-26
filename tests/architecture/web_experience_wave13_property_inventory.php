<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-013/014 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Property/Query/GetPropertyInventoryCollectionQuery.php',
    'symfony/src/Application/Property/Query/GetPropertyInventoryCollectionQueryHandler.php',
    'symfony/src/Web/Property/PropertyInventoryController.php',
    'symfony/src/Web/Property/PropertyInventoryPresenter.php',
    'symfony/src/Web/Property/ViewModel/PropertyInventoryViewModel.php',
    'symfony/templates/experience/property/inventory.html.twig',
] as $path)$read($path);

if(file_exists($root.'/app/Interfaces/Web/View/property/workspace_canonical.phtml')){
    throw new RuntimeException('VR-013/014 must retire workspace_canonical.phtml.');
}

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /property/manage','PropertyInventoryController::manage','path: /property/listing','PropertyInventoryController::listing'] as $marker){
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-013/014 route cutover incomplete: '.$marker);
}

$controller=$read('symfony/src/Web/Property/PropertyInventoryController.php');
foreach(['GetPropertyInventoryCollectionQuery','PageArchetype::Collection','DataGridQuery','WorkspaceShellFactory',"'Toolbar'","'DataGrid'"] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-013/014 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','PropertyWorkspaceReadModelInterface','Doctrine\\','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-013/014 controller leaked forbidden dependency: '.$forbidden);
}

$readModel=$read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyWorkspaceReadModel.php');
foreach(["int \$offset = 0","LIMIT ' . \$limit . ' OFFSET ' . \$offset","COUNT(*) AS total"] as $marker){
    if(!str_contains($readModel,$marker))throw new RuntimeException('VR-013/014 Property pagination incomplete: '.$marker);
}

$template=$read('symfony/templates/experience/property/inventory.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosToolbar','<twig:CosDataGrid','data-property-inventory','data-cos-archetype'] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-013/014 Collection composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-013/014 restored legacy/local presentation: '.$forbidden);
}

echo "Wave 13 VR-013/014 Property Inventory and Listing Collection passed.\n";
