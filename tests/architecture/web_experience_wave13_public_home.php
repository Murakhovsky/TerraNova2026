<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/PublicSite/HomeController.php',
    'symfony/templates/experience/public_shell.html.twig',
    'symfony/templates/experience/public/home.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-027 artifact missing: ' . $relative);
    }
}
if (is_file($root . '/symfony/src/Controller/HomePageController.php')) {
    throw new RuntimeException('VR-027 legacy HomePageController restored.');
}
if (is_file($root . '/app/Interfaces/Web/View/home/canonical.phtml')) {
    throw new RuntimeException('VR-027 home PHTML restored.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['path: /','App\\Web\\PublicSite\\HomeController'] as $marker){
    if(!str_contains($routes,$marker))throw new RuntimeException('VR-027 route contract incomplete: '.$marker);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/PublicSite/HomeController.php');
foreach(['PageArchetype::PublicDetailMarketing','PagePresentationFactory',"experience/public/home.html.twig"] as $marker){
    if(!str_contains($controller,$marker))throw new RuntimeException('VR-027 controller contract incomplete: '.$marker);
}
foreach(['PhtmlRenderer','ViteAssetManifest','public-surface'] as $legacy){
    if(str_contains($controller,$legacy))throw new RuntimeException('VR-027 controller restored legacy runtime: '.$legacy);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/public/home.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosCard','Company Operating System','/auth/login','/blog','/api/v1/status','data-cos-archetype'] as $marker){
    if(!str_contains($template,$marker))throw new RuntimeException('VR-027 template contract incomplete: '.$marker);
}
foreach(['tn-','style=','<script'] as $legacy){
    if(str_contains($template,$legacy))throw new RuntimeException('VR-027 template restored legacy/local presentation: '.$legacy);
}

echo "Wave 13 VR-027 root Public surface passed.\n";
