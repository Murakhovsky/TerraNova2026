<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Sales/Query/GetClientCaseCollectionQuery.php',
    'symfony/src/Application/Sales/Query/GetClientCaseCollectionQueryHandler.php',
    'symfony/src/Web/Sales/ClientCaseCollectionController.php',
    'symfony/src/Web/Sales/ClientCaseCollectionPresenter.php',
    'symfony/src/Web/Sales/ViewModel/ClientCaseCollectionViewModel.php',
    'symfony/src/Web/Sales/Component/ClientCaseCollectionItem.php',
    'symfony/src/Web/Sales/Component/ClientCaseFunnel.php',
    'symfony/templates/experience/client_case/index.html.twig',
    'symfony/templates/components/client_case/client_case_collection_item.html.twig',
    'symfony/templates/components/client_case/client_case_funnel.html.twig',
    'symfony/assets/styles/domains/client-case.css',
] as $relative) {
    if (!is_file($root . '/' . $relative)) throw new RuntimeException('VR-011 artifact is missing: ' . $relative);
}
if (is_file($root . '/app/Interfaces/Web/View/client_case/index.phtml')) {
    throw new RuntimeException('VR-011 must delete legacy Client Case Index PHTML.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['path: /client-case','ClientCaseCollectionController::index'] as $marker) {
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-011 route contract incomplete: '.$marker);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/Sales/ClientCaseCollectionController.php');
foreach(['GetClientCaseCollectionQuery','PageArchetype::Collection','WorkspaceShellFactory',"activeSection:'clients'","activeItem:'cases'","'EntityList'","'FilterBar'",'SessionCsrfValidator'] as $marker) {
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-011 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','Domains\\Clients','Doctrine\\','Repository'] as $forbidden) {
    if(str_contains($controller,$forbidden))throw new RuntimeException('VR-011 controller leaked dependency: '.$forbidden);
}

$query=(string)file_get_contents($root.'/symfony/src/Application/Sales/Query/GetClientCaseCollectionQueryHandler.php');
foreach(['ClientCaseReadModelFactoryInterface','SalesWorkspaceOperationalReadModelInterface','PropertyCatalogInterface','filters(','cases(','stats(','unlinkedInboundRequests(','openCaseOptions(','managerOptions(','propertyTypes(','locations(','pipelines('] as $marker) {
    if(!str_contains($query,$marker))throw new RuntimeException('VR-011 query composition incomplete: '.$marker);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/client_case/index.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosFilterBar','<twig:ClientCaseFunnel','<twig:ClientCaseCollectionItem','action="/client-case/create"','action="/client-case/createFromInboundRequest/','action="/client-case/linkInboundRequest"','data-client-case-collection','data-cos-archetype'] as $marker) {
    if(!str_contains($template,$marker))throw new RuntimeException('VR-011 Collection composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden) {
    if(str_contains($template,$forbidden))throw new RuntimeException('VR-011 restored legacy/local presentation: '.$forbidden);
}

$item=(string)file_get_contents($root.'/symfony/templates/components/client_case/client_case_collection_item.html.twig');
foreach(['/client-case/quickUpdate/','name="csrf_token"','name="return_url"','value="client-case"','name="stage_id"','name="status"','name="priority"','name="assigned_user_id"','type="submit"'] as $marker) {
    if(!str_contains($item,$marker))throw new RuntimeException('VR-011 quick-update parity incomplete: '.$marker);
}
foreach(['tn-','<table','data-controller='] as $forbidden) {
    if(str_contains($item,$forbidden))throw new RuntimeException('VR-011 Collection item restored legacy/local runtime: '.$forbidden);
}

if(is_file($root.'/symfony/src/Web/Sales/ClientCasePageController.php')) {
    throw new RuntimeException('VR-011 must not restore legacy ClientCasePageController after Phase 4 cutover.');
}

echo "Wave 13 VR-011 /client-case Collection passed.\n";
