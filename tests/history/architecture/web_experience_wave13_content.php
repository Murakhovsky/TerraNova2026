<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Application/Content/Query/GetContentAdministrationQueryHandler.php',
 'symfony/src/Application/Content/Query/GetContentEditorQueryHandler.php',
 'symfony/src/Application/Content/Command/SaveContentCommandHandler.php',
 'symfony/src/Web/Content/ContentAdminPageController.php',
 'symfony/src/Web/Content/ContentAdminPresenter.php',
 'symfony/src/Web/Content/ViewModel/ContentAdministrationViewModel.php',
 'symfony/src/Web/Content/ViewModel/ContentEditorViewModel.php',
 'symfony/templates/experience/content/manage.html.twig',
 'symfony/templates/experience/content/edit.html.twig',
] as $file){
 if(!is_file($root.'/'.$file)) throw new RuntimeException('VR-024 artifact missing: '.$file);
}
foreach([
 'app/Interfaces/Web/View/content/manage.phtml',
 'app/Interfaces/Web/View/content/edit.phtml',
] as $legacy){
 if(file_exists($root.'/'.$legacy)) throw new RuntimeException('VR-024 legacy artifact restored: '.$legacy);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/Content/ContentAdminPageController.php');
foreach(['GetContentAdministrationQuery','GetContentEditorQuery','SaveContentCommand','QueryBusInterface','CommandBusInterface','PageArchetype::SystemControlSurface','PageArchetype::FormEditor','SessionCsrfValidator'] as $marker){
 if(!str_contains($controller,$marker)) throw new RuntimeException('VR-024 controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','ContentServiceInterface'] as $forbidden){
 if(str_contains($controller,$forbidden)) throw new RuntimeException('VR-024 Web controller leaked forbidden dependency: '.$forbidden);
}

$manage=(string)file_get_contents($root.'/symfony/templates/experience/content/manage.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosToolbar','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:CosDataGrid','<twig:CosEntityListItem','id="n8n"'] as $marker){
 if(!str_contains($manage,$marker)) throw new RuntimeException('VR-024 manage composition incomplete: '.$marker);
}
$edit=(string)file_get_contents($root.'/symfony/templates/experience/content/edit.html.twig');
foreach(['<twig:CosPageHeader','class="cos-form"','class="cos-form__section"','class="cos-form__actions"','name="body_html"','name="schema_json"','name="csrf_token"'] as $marker){
 if(!str_contains($edit,$marker)) throw new RuntimeException('VR-024 editor composition incomplete: '.$marker);
}
foreach([$manage,$edit] as $surface){
 foreach(['tn-','style=','<script','<table'] as $forbidden){
  if(str_contains($surface,$forbidden)) throw new RuntimeException('VR-024 restored legacy/local presentation: '.$forbidden);
 }
}
echo "Wave 13 VR-024 Content Administration passed.\n";
