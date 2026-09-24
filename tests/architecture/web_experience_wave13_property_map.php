<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-017 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Property/Query/GetPropertyMapQuery.php',
    'symfony/src/Application/Property/Query/GetPropertyMapQueryHandler.php',
    'symfony/src/Web/Property/PropertyMapController.php',
    'symfony/src/Web/Property/PropertyMapPresenter.php',
    'symfony/src/Web/Property/ViewModel/PropertyMapViewModel.php',
    'symfony/templates/experience/property/map.html.twig',
    'symfony/assets/controllers/property_map_controller.js',
    'symfony/assets/styles/domains/property.css',
] as $path)$read($path);

if(file_exists($root.'/app/Interfaces/Web/View/property/map.phtml')){
    throw new RuntimeException('VR-017 legacy Property map PHTML restored.');
}

$registry=$read('symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
foreach([
    'PageArchetype::MapSpatial',
    "['PageHeader', 'Toolbar', 'ContextPanel']",
    "['FilterBar', 'KpiStrip', 'ActionBar', 'EntityList', 'EmptyState', 'ErrorState']",
] as $marker){
    if(!str_contains($registry,$marker))throw new RuntimeException('Map / Spatial archetype contract incomplete: '.$marker);
}

$controller=$read('symfony/src/Web/Property/PropertyMapController.php');
foreach(['GetPropertyMapQuery','PageArchetype::MapSpatial','PagePresentationFactory'] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-017 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','TenantContextProviderInterface','PropertyCatalogInterface','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-017 public map controller leaked forbidden dependency: '.$forbidden);
}

$service=$read('symfony/src/Application/Property/Service/PublicPropertyReadService.php');
foreach(["'latitude' =>","'longitude' =>"] as $marker){
    if(!str_contains($service,$marker))throw new RuntimeException('VR-017 public read payload lost coordinates: '.$marker);
}

$template=$read('symfony/templates/experience/property/map.html.twig');
foreach([
    "extends 'base.html.twig'",
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosContextPanel',
    '<twig:CosEntityListItem',
    'data-controller="property-map"',
    'data-x="{{ point.x }}"',
    'data-y="{{ point.y }}"',
    'data-cos-archetype',
] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-017 Map / Spatial composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-017 restored legacy/local map presentation: '.$forbidden);
}

$stimulus=$read('symfony/assets/controllers/property_map_controller.js');
foreach(['positionPins','pin.style.left','pin.style.top','dataset.x','dataset.y'] as $marker){
    if(!str_contains($stimulus,$marker))throw new RuntimeException('VR-017 map positioning adapter incomplete: '.$marker);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /property/map','PropertyMapController::index'] as $marker){
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-017 route cutover incomplete: '.$marker);
}

$security=$read('symfony/config/packages/security.yaml');
if(str_contains($security,'property/(?:map')){
    throw new RuntimeException('VR-017 must preserve public /property/map access semantics.');
}

echo "Wave 13 VR-017 public Property Map / Spatial surface passed.\n";
