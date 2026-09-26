<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('Phase 5 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

$tracker=$read('docs/03-architecture/wave13-migration-tracker.md');
foreach([
    '| VR-013 | `/property/manage` | Workspace | Property | Collection | P0 | Twig | DONE |',
    '| VR-014 | `/property/listing` | Workspace | Property | Collection | P0 | Twig | DONE |',
    '| VR-015 | `/property/submissions` | Workspace | Property | Operational Queue | P0 | Twig | DONE |',
    '| VR-016 | `/property/submission/{id}` | Workspace | Property | Entity Workspace | P0 | Twig | DONE |',
    '| VR-017 | `/property/map` | Public | Property | Map / Spatial | P0 | Twig | DONE |',
    '| VR-018 | `/spatial/manage` | Workspace | Property / Spatial | Map / Spatial | P0 | Twig | DONE |',
    '## Фаза 5 — завершення Property Workspace',
    'Phase 6 — System / Admin UI',
] as $marker){
    if(!str_contains($tracker,$marker))throw new RuntimeException('Phase 5 tracker incomplete: '.$marker);
}

foreach([
    'app/Interfaces/Web/View/property/workspace_canonical.phtml',
    'app/Interfaces/Web/View/property/submissions.phtml',
    'app/Interfaces/Web/View/property/submission_canonical.phtml',
    'app/Interfaces/Web/View/property/map.phtml',
    'app/Interfaces/Web/View/spatial/manage.phtml',
    'frontend/entrypoints/property-workspace.js',
    'frontend/features/property/workspace.css',
    'frontend/features/property/workspace.js',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('Phase 5 retired artifact restored: '.$retired);
}

$routes=$read('symfony/config/routes.yaml');
foreach([
    'PropertyInventoryController::manage',
    'PropertyInventoryController::listing',
    'PropertySubmissionsController::index',
    'PropertySubmissionController::index',
    'PropertyMapController::index',
    'SpatialManageController::index',
] as $owner){
    if(!str_contains($routes,$owner))throw new RuntimeException('Phase 5 route owner missing: '.$owner);
}
if(!str_contains($routes,'PublicPropertyCatalogController::index'))throw new RuntimeException('Phase 5 retained Public Catalog route migrated to canonical owner but route is missing.');

foreach([
    'PropertyPageController::manage',
    'PropertyPageController::listing',
    'PropertyPageController::submissions',
    'PropertyPageController::submission',
    'PropertyPageController::map',
    'SpatialPageController::manage',
] as $retiredOwner){
    if(str_contains($routes,$retiredOwner))throw new RuntimeException('Phase 5 legacy route owner restored: '.$retiredOwner);
}

$registry=$read('symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
foreach(['PageArchetype::MapSpatial',"['PageHeader', 'Toolbar', 'ContextPanel']"] as $marker){
    if(!str_contains($registry,$marker))throw new RuntimeException('Map / Spatial contract incomplete: '.$marker);
}

$propertyCss=$read('symfony/assets/styles/domains/property.css');
preg_match_all('/@media\s*\(max-width:\s*([^\)]+)\)/',$propertyCss,$matches);
$allowed=['1050px','650px','390px'];
foreach($matches[1]??[] as $breakpoint){
    if(!in_array(trim($breakpoint),$allowed,true))throw new RuntimeException('Property domain introduced non-canonical breakpoint: '.$breakpoint);
}

$vite=$read('vite.config.js');
if(str_contains($vite,"'property-workspace'"))throw new RuntimeException('Phase 5 retired Property Vite entry restored.');

$propertyPage=$read('symfony/src/Web/Property/PropertyPageController.php');
foreach(['public function favour(','public function show(','public function presentation(','public function submit('] as $retained){
    if(!str_contains($propertyPage,$retained))throw new RuntimeException('Phase 5 accidentally removed retained public Property scenario: '.$retained);
}
foreach(['public function manage(','public function listing(','public function submissions(','public function submission(','public function map('] as $retired){
    if(str_contains($propertyPage,$retired))throw new RuntimeException('Phase 5 left workspace ownership in public Property controller: '.$retired);
}

$spatialPage=$read('symfony/src/Web/Spatial/SpatialPageController.php');
foreach(['public function edit(','public function upload(','public function publish(','public function scene('] as $retained){
    if(!str_contains($spatialPage,$retained))throw new RuntimeException('Phase 5 accidentally removed retained Spatial scenario: '.$retained);
}
if(str_contains($spatialPage,'public function manage('))throw new RuntimeException('Phase 5 left duplicate Spatial manage ownership.');

echo "Wave 13 Phase 5 Property Workspace complete: canonical Twig units, legacy workspace runtime retired.\n";
