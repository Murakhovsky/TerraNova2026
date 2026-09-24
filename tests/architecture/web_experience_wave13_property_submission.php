<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-016 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Property/Query/GetPropertySubmissionWorkspaceQuery.php',
    'symfony/src/Application/Property/Query/GetPropertySubmissionWorkspaceQueryHandler.php',
    'symfony/src/Web/Property/PropertySubmissionController.php',
    'symfony/src/Web/Property/PropertySubmissionPresenter.php',
    'symfony/src/Web/Property/ViewModel/PropertySubmissionViewModel.php',
    'symfony/templates/experience/property/submission.html.twig',
] as $path)$read($path);

foreach([
    'app/Interfaces/Web/View/property/submission_canonical.phtml',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('VR-016 retired artifact restored: '.$retired);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/PropertyWebProvider.php');
foreach(["new WorkspaceDefinition('property.submission'","'property.submission', 20"] as $marker){
    if(!str_contains($provider,$marker))throw new RuntimeException('VR-016 Workspace registration incomplete: '.$marker);
}

$controller=$read('symfony/src/Web/Property/PropertySubmissionController.php');
foreach(['GetPropertySubmissionWorkspaceQuery','PageArchetype::EntityWorkspace','WorkspaceCompositionResolver',"'property.submission'","new EntityRef('property.submission'"] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-016 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','PropertyWorkspaceReadModelInterface','NavigationBuilder','Doctrine\\','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-016 controller leaked forbidden dependency: '.$forbidden);
}

$template=$read('symfony/templates/experience/property/submission.html.twig');
foreach(['<twig:CosWorkspace','<twig:CosEntityHeader','class="cos-kpi-strip"','data-property-submission','data-cos-archetype'] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-016 Entity Workspace composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-016 restored legacy/local presentation: '.$forbidden);
}

$legacy=$read('symfony/src/Web/Property/PropertyPageController.php');
foreach(['PropertyWorkspaceReadModelInterface','NavigationBuilder','workspaceHtml(','public function submission('] as $retired){
    if(str_contains($legacy,$retired))throw new RuntimeException('VR-016 left workspace ownership in public PropertyPageController: '.$retired);
}

$routes=$read('symfony/config/routes.yaml');
if(!str_contains($routes,'PropertySubmissionController::index'))throw new RuntimeException('VR-016 route cutover incomplete.');

echo "Wave 13 VR-016 Property Submission Entity Workspace passed.\n";
