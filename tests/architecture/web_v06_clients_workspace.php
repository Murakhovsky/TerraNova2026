<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('Missing WEB V0.6 Clients artifact: '.$path);
    return (string)file_get_contents($full);
};
$contains=static function(string $source,string $needle,string $message):void{
    if(!str_contains($source,$needle))throw new RuntimeException($message.' Missing: '.$needle);
};
$notContains=static function(string $source,string $needle,string $message):void{
    if(str_contains($source,$needle))throw new RuntimeException($message.' Forbidden: '.$needle);
};

$inboxController=$read('symfony/src/Web/Sales/ClientCaseInboxController.php');
$collectionController=$read('symfony/src/Web/Sales/ClientCaseCollectionController.php');
$workspaceController=$read('symfony/src/Web/Sales/ClientCaseWorkspaceController.php');
$mutationController=$read('symfony/src/Web/Sales/ClientCaseMutationController.php');
$provider=$read('symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');
$routes=$read('symfony/config/routes.yaml');
$vite=$read('vite.config.js');
$assetGate=$read('tests/architecture/frontend_assets.php');

foreach([
    $inboxController=>$needles=['GetClientCaseInboxQuery','PageArchetype::OperationalQueue','WorkspaceShellFactory',"activeSection: 'clients'"],
    $collectionController=>['GetClientCaseCollectionQuery','PageArchetype::Collection','WorkspaceShellFactory',"activeSection:'clients'"],
    $workspaceController=>['GetClientCaseWorkspaceQuery','PageArchetype::EntityWorkspace','WorkspaceCompositionResolver',"'sales.client_case'","new EntityRef('sales.deal'"],
] as $source=>$needles){
    foreach($needles as $needle)$contains($source,$needle,'Canonical Clients controller contract is incomplete.');
    $notContains($source,'Domains\\Clients','Clients presentation must not invent a Clients Domain.');
    $notContains($source,'PhtmlRenderer','Canonical Clients read controller must not depend on PHTML.');
}

foreach([
    'createOpportunity','updateOpportunity','quickUpdateOpportunity','addOpportunityActivity',
    'updateLead','convertLeadToOpportunity','attachInboundRequest','updateOpportunityPropertyMatch',
    'SessionCsrfValidator','SalesWriteServiceFactoryInterface',
] as $needle){
    $contains($mutationController,$needle,'Client Case mutation controller lost write parity.');
}
foreach(['PhtmlRenderer','NavigationBuilder','Domains\\Clients'] as $forbidden){
    $notContains($mutationController,$forbidden,'Mutation controller leaked presentation/invalid domain dependency.');
}

foreach([
    "new NavigationContribution('clients'",
    "new NavigationContribution('inbox'",
    "new NavigationContribution('cases'",
    "new WorkspaceDefinition('sales.client_case'",
    "'sales.client_case'",
] as $needle){
    $contains($provider,$needle,'Sales provider lost Clients workspace ownership.');
}

foreach([
    'path: /client-case',
    'ClientCaseCollectionController::index',
    'path: /client-case/inbox',
    'ClientCaseInboxController::index',
    'path: /client-case/show/{id}',
    'ClientCaseWorkspaceController::index',
    'ClientCaseMutationController::create',
    'ClientCaseMutationController::update',
    'ClientCaseMutationController::quickUpdate',
    'ClientCaseMutationController::activity',
    'ClientCaseMutationController::updateInboundRequest',
    'ClientCaseMutationController::createFromInboundRequest',
    'ClientCaseMutationController::linkInboundRequest',
    'ClientCaseMutationController::updatePropertyMatch',
] as $needle){
    $contains($routes,$needle,'Client Case routes are incomplete.');
}

foreach([
    'symfony/templates/experience/client_case/inbox.html.twig',
    'symfony/templates/experience/client_case/index.html.twig',
    'symfony/templates/experience/client_case/show.html.twig',
] as $path){
    $view=$read($path);
    foreach(['tn-','style=','<script'] as $forbidden)$notContains($view,$forbidden,'Canonical Client Case Twig restored legacy/local presentation: '.$path);
}

foreach([
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'symfony/src/Web/Sales/ClientCasePageController.php',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('Retired Client Case artifact restored: '.$retired);
}

$notContains($vite,"'clients-workspace'","Vite must not restore retired Clients bundle.");
$notContains($assetGate,"'clients-workspace'","Frontend asset gate must not require retired Clients bundle.");

echo "WEB V0.6 Clients Workspace final canonical contract passed.\n";
