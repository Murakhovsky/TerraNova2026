<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Application/Property/Query/GetPublicPropertySubmitFormQuery.php',
 'symfony/src/Application/Property/Query/GetPublicPropertySubmitFormQueryHandler.php',
 'symfony/src/Application/Property/Command/SubmitPublicPropertyCommand.php',
 'symfony/src/Application/Property/Command/SubmitPublicPropertyCommandHandler.php',
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
 'GetPublicPropertySubmitFormQuery','QueryBusInterface','CommandBusInterface',
 'SubmitPublicPropertyCommand','PageArchetype::FormEditor',
 "'PageHeader', 'FormSection', 'StickyActions', 'ErrorState'",
 'HTTP_CREATED','HTTP_UNPROCESSABLE_ENTITY','UploadedFile',
] as $marker){
 if(!str_contains($controller,$marker))throw new RuntimeException('VR-032 controller contract incomplete: '.$marker);
}
foreach(['PropertyCatalogInterface','PhtmlRenderer','INTAKE_UNAVAILABLE'] as $forbidden){
 if(str_contains($controller,$forbidden))throw new RuntimeException('VR-032 restored retired/bypass intake dependency: '.$forbidden);
}

$commandHandler=(string)file_get_contents($root.'/symfony/src/Application/Property/Command/SubmitPublicPropertyCommandHandler.php');
foreach(['PropertySubmissionInterface','->submit(','sourcePage','files'] as $marker){
 if(!str_contains($commandHandler,$marker))throw new RuntimeException('VR-032 canonical write handler incomplete: '.$marker);
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
 'Заявка одразу потрапляє в canonical Property intake queue.',
] as $marker){
 if(!str_contains($template,$marker))throw new RuntimeException('VR-032 FormEditor composition incomplete: '.$marker);
}
foreach(['tn-','style=','onclick=','Canonical intake ще не підключений'] as $forbidden){
 if(str_contains($template,$forbidden))throw new RuntimeException('VR-032 restored legacy/local/incomplete presentation: '.$forbidden);
}

$legacy=(string)file_get_contents($root.'/symfony/src/Web/Property/PropertyPageController.php');
if(str_contains($legacy,'public function submit(')){
 throw new RuntimeException('VR-032 duplicate legacy submit ownership remains.');
}

echo "Wave 13 VR-032 /property/submit canonical write + FormEditor passed.\n";
