<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); $read=static fn(string $p):string=>is_file($root.'/'.$p)?(string)file_get_contents($root.'/'.$p):throw new RuntimeException('Missing: '.$p);
$import=$read('symfony/importmap.php');foreach(["'bootstrap' =>","'chart.js' =>","'tabulator-tables' =>","'fullcalendar' =>","'sortablejs' =>","'flatpickr' =>","'cytoscape' =>"] as $m)if(!str_contains($import,$m))throw new RuntimeException('ImportMap dependency missing: '.$m);
$css=$read('symfony/assets/styles/app.css');foreach(['tokens.css','typography.css','geometry.css','primitives.css','business-patterns.css','mobile-foundation.css'] as $m)if(!str_contains($css,$m))throw new RuntimeException('COS CSS composition missing: '.$m);
$vite=$read('vite.config.js');if(str_contains($vite,'frontend/entrypoints/'))throw new RuntimeException('Generic Vite entrypoints retired.');if(!str_contains($vite,"'spatial-viewer': resolve(import.meta.dirname, 'frontend/spatial/spatial-viewer.js')"))throw new RuntimeException('Vite must be isolated to Spatial.');
$package=json_decode($read('package.json'),true,512,JSON_THROW_ON_ERROR);$d=$package['dependencies']??[];foreach(['three','@sparkjsdev/spark'] as $x)if(!isset($d[$x]))throw new RuntimeException('Spatial dependency missing: '.$x);foreach(['jquery','react','react-dom','vue','@angular/core','alpinejs'] as $x)if(isset($d[$x])||isset($package['devDependencies'][$x]))throw new RuntimeException('Unapproved global framework: '.$x);
echo "WEB standard stack passed: Symfony UX/AssetMapper canonical, Vite Spatial-only.\n";
