<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $full=$root.'/'.$path;
    if(!is_file($full))throw new RuntimeException('Frontend architecture file missing: '.$path);
    return (string)file_get_contents($full);
};
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$assert(!is_dir($root.'/app/Domains/Frontend'),'Frontend must remain Presentation, not a DDD Domain.');

foreach([
    'symfony/src/Web/Phtml/PhtmlRenderer.php',
    'symfony/src/Web/Phtml/ViteAssetManifest.php',
    'symfony/src/Web/Navigation/NavigationBuilder.php',
    'symfony/src/Web/Sales/SalesPageController.php',
    'symfony/src/Web/Sales/SalesAdminPageController.php',
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php',
    'symfony/src/Web/Content/ContentAdminPageController.php',
    'app/Interfaces/Web/View/components/workspace_sidebar.phtml',
    'app/Interfaces/Web/View/components/workspace_topbar.phtml',
    'app/Interfaces/Web/View/components/workspace_mobile_nav.phtml',
    'app/Interfaces/Web/View/components/ui/page_header.phtml',
    'app/Interfaces/Web/View/components/ui/kpi_card.phtml',
    'app/Interfaces/Web/View/components/ui/state.phtml',
    'app/Interfaces/Web/View/components/ui/status_badge.phtml',
    'app/Interfaces/Web/View/components/ui/tabs.phtml',
    'app/Interfaces/Web/View/sales/dashboard.phtml',
    'app/Interfaces/Web/View/sales/today.phtml',
    'app/Interfaces/Web/View/sales/pipeline.phtml',
    'app/Interfaces/Web/View/sales/leads.phtml',
    'app/Interfaces/Web/View/sales/deals.phtml',
    'app/Interfaces/Web/View/sales/deal.phtml',
    'app/Interfaces/Web/View/sales/director.phtml',
    'frontend/core/workspace-shell.js',
    'frontend/entrypoints/terranova-interface.js',
    'frontend/entrypoints/diagnostics-methodology-studio.js',
    'frontend/entrypoints/sales-workspace.js',
    'frontend/styles/interface.css',
    'frontend/styles/foundation.css',
    'frontend/styles/components.css',
    'frontend/styles/patterns.css',
    'frontend/styles/workspace.css',
] as $path)$read($path);

$navigation=$read('symfony/src/Web/Navigation/NavigationBuilder.php');
foreach([
    'ActiveModuleResolver',
    'snapshot($organizationId)',
    "'key' => 'sales'",
    "'key' => 'properties'",
    "'key' => 'diagnostics'",
    "'path' => 'cos/architecture'",
    "'path' => 'admin/content'",
] as $needle){
    $assert(str_contains($navigation,$needle),'Canonical Symfony navigation missing: '.$needle);
}
foreach(['salesNavigationContributor','propertyNavigationContributor','diagnosticNavigationContributor','Interfaces\\Web\\Navigation'] as $retired){
    $assert(!str_contains($navigation,$retired),'Symfony navigation restored retired contributor: '.$retired);
}

$sales=$read('symfony/src/Web/Sales/SalesPageController.php');
foreach(["'workspaceSection' => 'sales'","['sales-workspace']",'public function deals('] as $needle){
    $assert(str_contains($sales,$needle),'Sales Symfony page owner missing workspace contract: '.$needle);
}
$diagnostic=$read('symfony/src/Web/Diagnostic/DiagnosticPageController.php');
$assert(str_contains($diagnostic,"['diagnostics-methodology-studio']"),'Diagnostic Symfony owner must load its Vite entrypoint.');

$routes=$read('symfony/config/routes.yaml');
foreach(['cos_web_sales_deals:','/sales/deals','/cos/architecture','/admin/content'] as $needle){
    $assert(str_contains($routes,$needle),'Canonical Symfony route missing: '.$needle);
}

foreach([
    'app/bootstrap_web.php',
    'app/config/services_web.php',
    'app/Bootstrap/WebApplicationServices.php',
    'app/Interfaces/Web/Module.php',
    'app/Interfaces/Web/Controller',
    'app/Interfaces/Web/Routing',
    'app/Interfaces/Web/Navigation',
    'app/Interfaces/Web/Service',
] as $retired){
    $assert(!file_exists($root.'/'.$retired),'Retired Web runtime returned: '.$retired);
}

$views=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Interfaces/Web/View',FilesystemIterator::SKIP_DOTS));
foreach($views as $view){
    if(!$view->isFile()||strtolower($view->getExtension())!=='phtml')continue;
    $source=(string)file_get_contents($view->getPathname());
    if(str_contains($source,'/assets/js/')||str_contains($source,'/assets/css/')){
        throw new RuntimeException('PHTML must not bypass Vite: '.$view->getPathname());
    }
}

echo "Frontend interface architecture passed on Symfony-only presentation runtime.\n";
