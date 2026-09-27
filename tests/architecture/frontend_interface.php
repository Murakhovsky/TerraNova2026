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
    'symfony/templates/base.html.twig',
    'symfony/templates/experience/workspace_shell.html.twig',
    'symfony/templates/experience/public_shell.html.twig',
    'symfony/assets/app.js',
    'symfony/assets/styles/app.css',
    'symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php',
    'symfony/src/Web/Experience/Pattern/PatternRegistry.php',
    'symfony/src/Web/Experience/Shell/WorkspaceShellFactory.php',
    'symfony/src/Web/Experience/Asset/ViteAssetManifest.php',
    'symfony/src/Web/Diagnostic/DiagnosticPageController.php',
    'symfony/templates/experience/diagnostic/report.html.twig',
    'symfony/src/Web/Property/PropertyPageController.php',
    'symfony/templates/experience/public/property_presentation.html.twig',
    'symfony/src/Web/Spatial/SpatialPageController.php',
    'symfony/templates/experience/spatial/edit.html.twig',
    'symfony/templates/experience/public/spatial_scene.html.twig',
] as $path)$read($path);

foreach([
    'symfony/src/Web/Phtml/PhtmlRenderer.php',
    'symfony/src/Web/Phtml/UrlHelper.php',
    'symfony/src/Web/Phtml/RequestQueryAdapter.php',
    'symfony/src/Web/Phtml/ViteAssetManifest.php',
] as $retired){
    $assert(!is_file($root.'/'.$retired),'Retired PHTML Web runtime restored: '.$retired);
}

$routes=$read('symfony/config/routes.yaml');
foreach([
    'path: /admin','path: /sales/dashboard','path: /client-case',
    'path: /property/manage','path: /property/presentation/{slug}',
    'path: /cos/control-center','path: /cabinet','path: /auth/login',
] as $needle)$assert(str_contains($routes,$needle),'Canonical Symfony route missing: '.$needle);

foreach([
    'symfony/templates/experience/diagnostic/report.html.twig',
    'symfony/templates/experience/public/property_presentation.html.twig',
    'symfony/templates/experience/spatial/edit.html.twig',
    'symfony/templates/experience/public/spatial_scene.html.twig',
] as $template){
    $source=$read($template);
    foreach(['tn-','onclick=','style='] as $legacy)$assert(!str_contains($source,$legacy),'Canonical Twig restored legacy/local presentation: '.$template.' -> '.$legacy);
}

$vite=$read('vite.config.js');
$assert(!str_contains($vite,'frontend/entrypoints/'),'Generic/page Vite entrypoint restored.');
$assert(str_contains($vite,"'spatial-viewer':"),'Specialized Spatial viewer Vite island missing.');

$viewRoot=$root.'/app/Interfaces/Web/View';
$pagePhtml=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot,FilesystemIterator::SKIP_DOTS)) as $view){
    if(!$view->isFile()||strtolower($view->getExtension())!=='phtml')continue;
    $relative=str_replace('\\','/',substr($view->getPathname(),strlen($viewRoot)+1));
    if($relative==='index.phtml'||str_starts_with($relative,'components/')||str_starts_with($relative,'shared/'))continue;
    $pagePhtml[]=$relative;
}
sort($pagePhtml);
$assert($pagePhtml===['property/pdf.phtml'],'Only non-Web Property PDF PHTML may remain.');

echo "Frontend interface architecture passed on canonical Symfony/Twig Experience Platform.\n";
