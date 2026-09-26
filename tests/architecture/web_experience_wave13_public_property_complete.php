<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

$tracker=(string)file_get_contents($root.'/docs/03-architecture/wave13-migration-tracker.md');
foreach(['VR-027','VR-028','VR-029','VR-030','VR-031','VR-032'] as $id){
    if(preg_match('/\| '.preg_quote($id,'/').' \|[^\n]*\| DONE \|/',$tracker)!==1){
        throw new RuntimeException('Phase 8 tracker unit is not DONE: '.$id);
    }
}
if(!str_contains($tracker,'## Фаза 8 — завершення Public Property')){
    throw new RuntimeException('Phase 8 closure section is missing.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
 'path: /','App\\Web\\PublicSite\\HomeController',
 'path: /property/catalog','PublicPropertyCatalogController::index',
 'path: /property/show/{slug}','PublicPropertyDetailController::show',
 'path: /property/type/{code}','PublicPropertySeoController::type',
 'path: /property/city/{slug}','PublicPropertySeoController::city',
 'path: /nerukhomist/{location}/{type}','PublicPropertySeoController::landing',
 'path: /property/favour','PublicPropertyFavouritesController::index',
 'path: /property/submit','path: /property/create','path: /submit-property',
 'PublicPropertySubmitController::index',
] as $marker){
 if(!str_contains($routes,$marker))throw new RuntimeException('Phase 8 route ownership incomplete: '.$marker);
}

foreach([
 'app/Interfaces/Web/View/home/canonical.phtml',
 'app/Interfaces/Web/View/property/catalog.phtml',
 'app/Interfaces/Web/View/property/show.phtml',
 'app/Interfaces/Web/View/property/seo.phtml',
 'app/Interfaces/Web/View/property/favour.phtml',
 'app/Interfaces/Web/View/property/submit.phtml',
 'frontend/entrypoints/terranova-catalog-api.js',
 'frontend/entrypoints/terranova-property-gallery.js',
] as $retired){
 if(is_file($root.'/'.$retired))throw new RuntimeException('Phase 8 retired artifact restored: '.$retired);
}

foreach([
 'symfony/templates/experience/public/home.html.twig',
 'symfony/templates/experience/public/property_catalog.html.twig',
 'symfony/templates/experience/public/property_detail.html.twig',
 'symfony/templates/experience/public/property_seo.html.twig',
 'symfony/templates/experience/public/property_favourites.html.twig',
 'symfony/templates/experience/public/property_submit.html.twig',
] as $template){
 if(!is_file($root.'/'.$template))throw new RuntimeException('Phase 8 canonical template missing: '.$template);
 $source=(string)file_get_contents($root.'/'.$template);
 foreach(['tn-','style=','onclick='] as $forbidden){
  if(str_contains($source,$forbidden))throw new RuntimeException('Phase 8 canonical template restored legacy/local presentation: '.$template.' -> '.$forbidden);
 }
}

$legacy=(string)file_get_contents($root.'/symfony/src/Web/Property/PropertyPageController.php');
foreach([
 'public function catalog(','public function show(','public function type(','public function city(',
 'public function landing(','public function favour(','public function submit(',
] as $retiredOwner){
 if(str_contains($legacy,$retiredOwner))throw new RuntimeException('Phase 8 duplicate legacy route ownership remains: '.$retiredOwner);
}
foreach(['public function presentation(','public function pdf(','public function presentationShare('] as $specialized){
 if(!str_contains($legacy,$specialized))throw new RuntimeException('Specialized compatibility route accidentally removed during Phase 8: '.$specialized);
}

foreach([
 'app/Interfaces/Web/View/property/presentation.phtml',
 'app/Interfaces/Web/View/property/pdf.phtml',
] as $specializedView){
 if(!is_file($root.'/'.$specializedView))throw new RuntimeException('Specialized compatibility view unexpectedly removed during Phase 8: '.$specializedView);
}

$card=(string)file_get_contents($root.'/symfony/templates/components/property/public_property_card.html.twig');
if(!str_contains($card,'href="{{ property.url }}#request"')){
 throw new RuntimeException('Phase 8 shared property-card request CTA must target the detail-page request form.');
}
if(str_contains($card,'href="#request"')){
 throw new RuntimeException('Phase 8 shared property-card request CTA restored a page-local dead anchor.');
}

$vite=(string)file_get_contents($root.'/vite.config.js');
foreach(["'terranova-catalog-api'","'terranova-property-gallery'"] as $retiredEntry){
 if(str_contains($vite,$retiredEntry))throw new RuntimeException('Phase 8 retired Vite entry remains configured: '.$retiredEntry);
}

echo "Wave 13 Phase 8 Public Property family complete.\n";
