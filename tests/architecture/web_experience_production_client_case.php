<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('Missing Client Case production artifact: '.$path);
    return (string)file_get_contents($full);
};
$contains=static function(string $source,string $needle,string $message):void{
    if(!str_contains($source,$needle))throw new RuntimeException($message.' Missing: '.$needle);
};
$notContains=static function(string $source,string $needle,string $message):void{
    if(str_contains($source,$needle))throw new RuntimeException($message.' Forbidden: '.$needle);
};

$inbox=$read('symfony/templates/experience/client_case/inbox.html.twig');
foreach(['<twig:CosPageHeader','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:ClientCaseInboxItem','data-client-case-inbox'] as $marker){
    $contains($inbox,$marker,'Client Case Inbox composition incomplete.');
}

$inboxItem=$read('symfony/templates/components/client_case/client_case_inbox_item.html.twig');
foreach([
    '/client-case/updateInboundRequest/','/client-case/createFromInboundRequest/','/client-case/linkInboundRequest',
    'name="csrf_token"','name="return_url"','name="status"','name="assigned_user_id"',
    'name="next_contact_at"','name="activity_type"','name="manager_note"','name="activity_body"',
    'name="priority"','name="request_id"','name="case_id"',
] as $marker)$contains($inboxItem,$marker,'Client Case Inbox lost triage mutation parity.');

$index=$read('symfony/templates/experience/client_case/index.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosFilterBar','<twig:ClientCaseFunnel','<twig:ClientCaseCollectionItem','data-client-case-collection'] as $marker){
    $contains($index,$marker,'Client Case Collection composition incomplete.');
}

$collectionItem=$read('symfony/templates/components/client_case/client_case_collection_item.html.twig');
foreach([
    '/client-case/quickUpdate/','name="csrf_token"','name="return_url"','value="client-case"',
    'name="stage_id"','name="status"','name="priority"','name="assigned_user_id"',
] as $marker)$contains($collectionItem,$marker,'Client Case Collection lost quick-update parity.');

$show=$read('symfony/templates/experience/client_case/show.html.twig');
foreach([
    '<twig:CosWorkspace','<twig:CosEntityHeader','class="cos-kpi-strip"','<twig:CosTimeline',
    'data-client-case-workspace','/client-case/update/','/client-case/activity/',
    '/client-case/updatePropertyMatch/','/cos/action/','/cos/approval/',
    '/property/presentationShare','match.pdfHref',
    'name="csrf_token"','name="return_url"','name="full_name"','name="stage_id"',
    'name="assigned_user_id"','name="next_contact_at"','name="activity_type"',
    'name="match_status"','name="score"','name="note"',
] as $marker)$contains($show,$marker,'Client Case Entity Workspace lost composition/workflow parity.');
foreach(['tn-','style=','<script','<table'] as $forbidden)$notContains($show,$forbidden,'Client Case Workspace restored legacy/local presentation.');

$presenter=$read('symfony/src/Web/Sales/ClientCaseWorkspacePresenter.php');
foreach(["'/property/show/'","'/property/pdf/'"] as $marker){
    $contains($presenter,$marker,'Client Case presenter lost property deep-link construction.');
}

$workspaceController=$read('symfony/src/Web/Sales/ClientCaseWorkspaceController.php');
foreach(['GetClientCaseWorkspaceQuery','PageArchetype::EntityWorkspace','WorkspaceCompositionResolver',"'sales.client_case'","new EntityRef('sales.deal'"] as $marker){
    $contains($workspaceController,$marker,'Client Case Workspace controller cutover incomplete.');
}

$query=$read('symfony/src/Application/Sales/Query/GetClientCaseWorkspaceQueryHandler.php');
foreach([
    'ClientCaseReadModelFactoryInterface','PropertyCatalogInterface','SalesWorkspaceOperationalReadModelInterface',
    'OperationsReadModelInterface','inboundRequests(','activities(','propertyMatches(','requestMatches(',
    'managerOptions(','propertyTypes(','locations(','pipelines(','dealIntelligence(',
] as $marker)$contains($query,$marker,'Client Case Workspace query composition incomplete.');

$mutation=$read('symfony/src/Web/Sales/ClientCaseMutationController.php');
foreach([
    'createOpportunity','updateOpportunity','quickUpdateOpportunity','addOpportunityActivity',
    'updateLead','convertLeadToOpportunity','attachInboundRequest','updateOpportunityPropertyMatch',
    '$this->csrf->isValid($request)',
] as $marker)$contains($mutation,$marker,'Client Case mutation parity incomplete.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    'ClientCaseInboxController::index','ClientCaseCollectionController::index','ClientCaseWorkspaceController::index',
    'ClientCaseMutationController::create','ClientCaseMutationController::update',
    'ClientCaseMutationController::quickUpdate','ClientCaseMutationController::activity',
    'ClientCaseMutationController::updateInboundRequest','ClientCaseMutationController::createFromInboundRequest',
    'ClientCaseMutationController::linkInboundRequest','ClientCaseMutationController::updatePropertyMatch',
] as $marker)$contains($routes,$marker,'Client Case route ownership incomplete.');

foreach([
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'symfony/src/Web/Sales/ClientCasePageController.php',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('Retired Client Case production artifact restored: '.$retired);
}

echo "Client Case production adoption final contract passed.\n";
