<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('VR-012 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Application/Sales/Query/GetClientCaseWorkspaceQuery.php',
    'symfony/src/Application/Sales/Query/GetClientCaseWorkspaceQueryHandler.php',
    'symfony/src/Web/Sales/ClientCaseWorkspaceController.php',
    'symfony/src/Web/Sales/ClientCaseWorkspacePresenter.php',
    'symfony/src/Web/Sales/ViewModel/ClientCaseWorkspaceViewModel.php',
    'symfony/src/Web/Sales/ClientCaseMutationController.php',
    'symfony/templates/experience/client_case/show.html.twig',
] as $path)$read($path);

foreach([
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'symfony/src/Web/Sales/ClientCasePageController.php',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('VR-012 retired artifact restored: '.$retired);
}

$controller=$read('symfony/src/Web/Sales/ClientCaseWorkspaceController.php');
foreach([
    'GetClientCaseWorkspaceQuery',
    'PageArchetype::EntityWorkspace',
    'WorkspaceCompositionResolver',
    "'sales.client_case'",
    "new EntityRef('sales.deal'",
    "activeSection:'clients'",
    "activeItem:'cases'",
] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-012 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','Domains\\Clients','Doctrine\\','Repository'] as $forbidden){
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-012 controller leaked forbidden dependency: '.$forbidden);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');
foreach([
    "new WorkspaceDefinition('sales.client_case'",
    "['sales.lead', 'sales.deal', 'sales.client_case']",
] as $marker){
    if(!str_contains($provider,$marker))throw new RuntimeException('VR-012 Sales workspace registration incomplete: '.$marker);
}

$template=$read('symfony/templates/experience/client_case/show.html.twig');
foreach([
    '<twig:CosWorkspace',
    '<twig:CosEntityHeader',
    '<twig:CosTimeline',
    'class="cos-kpi-strip"',
    'data-client-case-workspace',
    '/client-case/update/',
    '/client-case/activity/',
    '/client-case/updatePropertyMatch/',
    '/cos/action/',
    '/cos/approval/',
    '/property/presentationShare',
    'match.pdfHref',
    'name="csrf_token"',
] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-012 Entity Workspace composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-012 restored legacy/local presentation: '.$forbidden);
}

$presenter=$read('symfony/src/Web/Sales/ClientCaseWorkspacePresenter.php');
foreach(["'/property/show/'","'/property/pdf/'"] as $marker){
    if(!str_contains($presenter,$marker))throw new RuntimeException('VR-012 presenter lost property route construction: '.$marker);
}

$mutation=$read('symfony/src/Web/Sales/ClientCaseMutationController.php');
foreach([
    'createOpportunity','updateOpportunity','quickUpdateOpportunity','addOpportunityActivity',
    'updateLead','convertLeadToOpportunity','attachInboundRequest','updateOpportunityPropertyMatch',
    'SessionCsrfValidator',
] as $marker){
    if(!str_contains($mutation,$marker))throw new RuntimeException('VR-012 mutation controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder'] as $forbidden){
    if(str_contains($mutation,$forbidden))throw new RuntimeException('VR-012 mutation controller leaked presentation dependency: '.$forbidden);
}

$routes=$read('symfony/config/routes.yaml');
foreach([
    'ClientCaseInboxController::index',
    'ClientCaseCollectionController::index',
    'ClientCaseWorkspaceController::index',
    'ClientCaseMutationController::create',
    'ClientCaseMutationController::update',
    'ClientCaseMutationController::quickUpdate',
    'ClientCaseMutationController::activity',
    'ClientCaseMutationController::updateInboundRequest',
    'ClientCaseMutationController::createFromInboundRequest',
    'ClientCaseMutationController::linkInboundRequest',
    'ClientCaseMutationController::updatePropertyMatch',
] as $marker){
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-012 route cutover incomplete: '.$marker);
}
if(str_contains($routes,'ClientCasePageController'))throw new RuntimeException('VR-012 left legacy ClientCasePageController route ownership.');

$vite=$read('vite.config.js');
if(str_contains($vite,"'clients-workspace'"))throw new RuntimeException('VR-012 restored retired clients-workspace Vite entrypoint.');

echo "Wave 13 VR-012 Client Case Entity Workspace passed.\n";
