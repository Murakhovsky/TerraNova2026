<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Application/Property/Query/GetPublicPropertyFavouritesQuery.php',
 'symfony/src/Application/Property/Query/GetPublicPropertyFavouritesQueryHandler.php',
 'symfony/src/Web/Property/PublicPropertyFavouritesController.php',
 'symfony/src/Web/Property/PublicPropertyFavouritesPresenter.php',
 'symfony/src/Web/Property/ViewModel/PublicPropertyFavouritesViewModel.php',
 'symfony/templates/experience/public/property_favourites.html.twig',
] as $relative){
 if(!is_file($root.'/'.$relative))throw new RuntimeException('VR-031 artifact missing: '.$relative);
}
if(is_file($root.'/app/Interfaces/Web/View/property/favour.phtml')){
 throw new RuntimeException('VR-031 retired Favourites PHTML restored.');
}

$port=(string)file_get_contents($root.'/app/Domains/Property/Application/Contract/PublicPropertyReadRepositoryInterface.php');
$repo=(string)file_get_contents($root.'/app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php');
foreach(['findByPublicIds','list<string>'] as $marker){
 if(!str_contains($port,$marker))throw new RuntimeException('VR-031 read port incomplete: '.$marker);
}
foreach(['findByPublicIds','p.public_id IN','array_slice','100'] as $marker){
 if(!str_contains($repo,$marker))throw new RuntimeException('VR-031 repository projection incomplete: '.$marker);
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['path: /property/favour','PublicPropertyFavouritesController::index'] as $marker){
 if(!str_contains($routes,$marker))throw new RuntimeException('VR-031 route contract incomplete: '.$marker);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/Property/PublicPropertyFavouritesController.php');
foreach([
 'GetPublicPropertyFavouritesQuery','QueryBusInterface','PageArchetype::PublicCatalog',
 "cos_public_property_favourites","experience/public/property_favourites.html.twig",
] as $marker){
 if(!str_contains($controller,$marker))throw new RuntimeException('VR-031 controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','PropertyCatalogInterface'] as $forbidden){
 if(str_contains($controller,$forbidden))throw new RuntimeException('VR-031 controller leaked legacy/domain dependency: '.$forbidden);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/public/property_favourites.html.twig');
foreach([
 '<twig:CosPageHeader','data-controller="public-property"','data-cos-public="property-favourites"',
 'data-public-property-target="item"','data-public-property-target="empty"','data-public-property-target="count"',
 "components/property/public_property_card.html.twig",
] as $marker){
 if(!str_contains($template,$marker))throw new RuntimeException('VR-031 Favourites composition incomplete: '.$marker);
}
foreach(['tn-','style=','onclick='] as $forbidden){
 if(str_contains($template,$forbidden))throw new RuntimeException('VR-031 restored legacy/local presentation: '.$forbidden);
}

$stimulus=(string)file_get_contents($root.'/symfony/assets/controllers/public_property_controller.js');
foreach(['itemTargets','emptyTarget','countTarget','/api/v1/public/properties/favourites'] as $marker){
 if(!str_contains($stimulus,$marker))throw new RuntimeException('VR-031 browser projection incomplete: '.$marker);
}
foreach(['localStorage','sessionStorage','innerHTML'] as $forbidden){
 if(str_contains($stimulus,$forbidden))throw new RuntimeException('VR-031 browser projection leaked forbidden ownership: '.$forbidden);
}

echo "Wave 13 VR-031 /property/favour Favourites passed.\n";
