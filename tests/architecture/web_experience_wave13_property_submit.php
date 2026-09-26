<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Application/Property/Query/GetPublicPropertySubmitFormQuery.php',
 'symfony/src/Application/Property/Query/GetPublicPropertySubmitFormQueryHandler.php',
 'symfony/src/Web/Property/PublicPropertySubmitController.php',
 'symfony/src/Web/Property/PublicPropertySubmitPresenter.php',
 'symfony/src/Web/Property/ViewModel/PublicPropertySubmitViewModel.php',
 'symfony/templates/experience/public/property_submit.html.twig',
] as $relative){
 if(!is_file($root.'/'.$relative))throw new RuntimeException('VR-032 artifact missing: '.$relative);
}
if(is_file($root.'/app/Interfaces/Web/View/property/submit.phtml')){
 throw new RuntimeException('VR-032 retired Public Submit PHTML restored.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
 'path: /property/submit','path: /property/create','path: /submit-property',
 'PublicPropertySubmitController::index',
] as $marker){
 if(!str_contains($routes,$marker))throw new RuntimeException('VR-032 route/alias contract incomplete: '.$marker);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/Property/PublicPropertySubmitController.php');
foreach([
 'GetPublicPropertySubmitFormQuery','QueryBusInterface','PageArchetype::FormEditor',
 "'PageHeader', 'FormSection', 'StickyActions', 'ErrorState'",
 'HTTP_SERVICE_UNAVAILABLE','INTAKE_UNAVAILABLE',
] as $marker){
 if(!str_contains($controller,$marker))throw new RuntimeException('VR-032 controller contract incomplete: '.$marker);
}
foreach(['CommandBusInterface','PropertyCatalogInterface','PhtmlRenderer'] as $forbidden){
 if(str_contains($controller,$forbidden))throw new RuntimeException('VR-032 must not invent/bypass public intake write: '.$forbidden);
}

$handler=(string)file_get_contents($root.'/symfony/src/Application/Property/Query/GetPublicPropertySubmitFormQueryHandler.php');
foreach(['PropertyCatalogInterface','propertyTypes()'] as $marker){
 if(!str_contains($handler,$marker))throw new RuntimeException('VR-032 form reference read incomplete: '.$marker);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/public/property_submit.html.twig');
foreach([
 '<twig:CosPageHeader','<twig:CosFormSection','<twig:CosStickyActions',
 'class="cos-form"','enctype="multipart/form-data"',
 'name="owner_name"','name="owner_phone"','name="property_type"','name="city"',
 'name="main_photo"','name="gallery_photos[]"','data-cos-public="property-submit"',
] as $marker){
 if(!str_contains($template,$marker))throw new RuntimeException('VR-032 FormEditor composition incomplete: '.$marker);
}
foreach(['tn-','style=','onclick='] as $forbidden){
 if(str_contains($template,$forbidden))throw new RuntimeException('VR-032 restored legacy/local presentation: '.$forbidden);
}

$legacy=(string)file_get_contents($root.'/symfony/src/Web/Property/PropertyPageController.php');
if(str_contains($legacy,'public function submit(')){
 throw new RuntimeException('VR-032 duplicate legacy submit ownership remains.');
}

echo "Wave 13 VR-032 /property/submit FormEditor passed.\n";
