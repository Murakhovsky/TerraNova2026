<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

$read=static function(string $path)use($root):string{
    $full=$root.'/'.ltrim($path,'/');
    if(!is_file($full))throw new RuntimeException('Phase 4 artifact missing: '.$path);
    return (string)file_get_contents($full);
};

$tracker=$read('docs/03-architecture/wave13-migration-tracker.md');
foreach([
    '| VR-010 | `/client-case/inbox` | Workspace | Sales / Clients | Operational Queue | P0 | Twig | DONE |',
    '| VR-011 | `/client-case` | Workspace | Sales / Clients | Collection | P0 | Twig | DONE |',
    '| VR-012 | `/client-case/show/{id}` | Workspace | Sales / Clients | Entity Workspace | P0 | Twig | DONE |',
    '## Фаза 4 — завершення Clients',
    'Client Case visual PHTML = **0**',
    'Phase 5 — Property Workspace',
] as $marker){
    if(!str_contains($tracker,$marker))throw new RuntimeException('Phase 4 migration tracker is incomplete: '.$marker);
}

foreach([
    'app/Interfaces/Web/View/client_case/inbox.phtml',
    'app/Interfaces/Web/View/client_case/index.phtml',
    'app/Interfaces/Web/View/client_case/show.phtml',
    'symfony/src/Web/Sales/ClientCasePageController.php',
    'frontend/entrypoints/clients-workspace.js',
    'frontend/features/clients/workspace.css',
] as $retired){
    if(file_exists($root.'/'.$retired))throw new RuntimeException('Phase 4 retired Client Case artifact restored: '.$retired);
}

foreach([
    'symfony/src/Web/Sales/ClientCaseInboxController.php',
    'symfony/src/Web/Sales/ClientCaseCollectionController.php',
    'symfony/src/Web/Sales/ClientCaseWorkspaceController.php',
    'symfony/src/Web/Sales/ClientCaseMutationController.php',
    'symfony/templates/experience/client_case/inbox.html.twig',
    'symfony/templates/experience/client_case/index.html.twig',
    'symfony/templates/experience/client_case/show.html.twig',
    'symfony/assets/styles/domains/client-case.css',
] as $canonical){
    $source=$read($canonical);
    if(str_contains($source,'Domains\\Clients'))throw new RuntimeException('Phase 4 invented forbidden Clients Domain: '.$canonical);
    if(str_ends_with($canonical,'.twig')||str_ends_with($canonical,'.css')){
        foreach(['tn-','style=','<script'] as $forbidden){
            if(str_contains($source,$forbidden))throw new RuntimeException('Phase 4 restored legacy/local presentation in '.$canonical.': '.$forbidden);
        }
    }
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
    if(!str_contains($routes,$marker))throw new RuntimeException('Phase 4 route ownership incomplete: '.$marker);
}
if(str_contains($routes,'ClientCasePageController'))throw new RuntimeException('Phase 4 legacy ClientCasePageController route ownership restored.');

$vite=$read('vite.config.js');
if(str_contains($vite,"'clients-workspace'"))throw new RuntimeException('Phase 4 retired Clients Vite bundle restored.');

echo "Wave 13 Phase 4 Clients complete: canonical Twig, Sales ownership, legacy visual runtime = 0.\n";
