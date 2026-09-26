<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Web/Property/PublicPropertySeoController.php',
 'symfony/src/Web/Property/PublicPropertySeoPresenter.php',
 'symfony/src/Web/Property/ViewModel/PublicPropertySeoViewModel.php',
 'symfony/templates/experience/public/property_seo.html.twig',
] as $relative){
 if(!is_file($root.'/'.$relative))throw new RuntimeException('VR-030 artifact missing: '.$relative);
}
if(is_file($root.'/app/Interfaces/Web/View/property/seo.phtml')){
 throw new RuntimeException('VR-030 retired Property SEO PHTML restored.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
 'path: /property/type/{code}','PublicPropertySeoController::type',
 'path: /property/city/{slug}','PublicPropertySeoController::city',
 'path: /nerukhomist/{location}/{type}','PublicPropertySeoController::landing',
] as $marker){
 if(!str_contains($routes,$marker))throw new RuntimeException('VR-030 route contract incomplete: '.$marker);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/Property/PublicPropertySeoController.php');
foreach([
 'GetPublicPropertyCatalogQuery','QueryBusInterface','PageArchetype::PublicCatalog',
 'PublicPropertyCatalogPresenter','PublicPropertySeoPresenter',"experience/public/property_seo.html.twig",
 "['type' => \$code]","['location' => \$slug]",
] as $marker){
 if(!str_contains($controller,$marker))throw new RuntimeException('VR-030 controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','PropertyCatalogInterface'] as $forbidden){
 if(str_contains($controller,$forbidden))throw new RuntimeException('VR-030 controller leaked direct legacy/domain dependency: '.$forbidden);
}

$catalogPresenter=(string)file_get_contents($root.'/symfony/src/Web/Property/PublicPropertyCatalogPresenter.php');
foreach(['string $pagePath','string $pageName','$pagePath !== \'/property/catalog\'','pageUrl($filters'] as $marker){
 if(!str_contains($catalogPresenter,$marker))throw new RuntimeException('VR-030 catalog reuse contract incomplete: '.$marker);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/public/property_seo.html.twig');
foreach([
 '<twig:CosPageHeader','data-controller="public-property"','data-cos-public="property-seo"',
 "components/property/public_property_card.html.twig",'application/ld+json','seo.catalog.previousUrl','seo.catalog.nextUrl',
] as $marker){
 if(!str_contains($template,$marker))throw new RuntimeException('VR-030 SEO composition incomplete: '.$marker);
}
foreach(['tn-','style=','onclick='] as $forbidden){
 if(str_contains($template,$forbidden))throw new RuntimeException('VR-030 restored legacy/local presentation: '.$forbidden);
}

$legacy=(string)file_get_contents($root.'/symfony/src/Web/Property/PropertyPageController.php');
foreach(['public function type(','public function city(','public function landing(','private function seo('] as $forbidden){
 if(str_contains($legacy,$forbidden))throw new RuntimeException('VR-030 duplicate legacy SEO ownership remains: '.$forbidden);
}

echo "Wave 13 VR-030 Property SEO collections passed.\n";
