<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php'; require $root.'/tests/architecture/zero_legacy_runtime.php';
foreach(['symfony/templates/base.html.twig','symfony/assets/styles/app.css','symfony/assets/app.js','symfony/src/Web/Experience/Asset/ViteAssetManifest.php','app/Interfaces/Web/View/property/pdf.phtml'] as $p)if(!is_file($root.'/'.$p))throw new RuntimeException('Missing production artifact: '.$p);
foreach(['app/Domains/Frontend','app/Domains/Public','app/Domains/Portal'] as $p)if(is_dir($root.'/'.$p))throw new RuntimeException('Presentation became a Domain: '.$p);
if(is_file($root.'/symfony/src/Web/Phtml/PhtmlRenderer.php'))throw new RuntimeException('PHTML renderer restored.');
$vite=(string)file_get_contents($root.'/vite.config.js'); if(str_contains($vite,'frontend/entrypoints/'))throw new RuntimeException('Generic Vite runtime restored.'); if(!str_contains($vite,"'spatial-viewer':"))throw new RuntimeException('Spatial Vite island missing.');
$import=(string)file_get_contents($root.'/symfony/importmap.php');foreach(["'app' =>","'public_home' =>","'public_property' =>","'spatial_admin' =>"] as $m)if(!str_contains($import,$m))throw new RuntimeException('ImportMap missing '.$m);
echo "Frontend production closure passed.\n";
