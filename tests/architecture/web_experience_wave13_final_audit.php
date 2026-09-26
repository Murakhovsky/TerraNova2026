<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $p):string=>is_file($root.'/'.$p)?(string)file_get_contents($root.'/'.$p):throw new RuntimeException('Missing: '.$p);
$tracker=$read('docs/03-architecture/wave13-migration-tracker.md');
for($i=1;$i<=47;$i++){ $id=sprintf('VR-%03d',$i); if(preg_match('/\\| '.preg_quote($id,'/').' \\|[^\\n]*\\| DONE \\|/',$tracker)!==1)throw new RuntimeException('Not DONE: '.$id); }
foreach(['status: closed','## Wave 13 — 100% debt closure'] as $m)if(!str_contains($tracker,$m))throw new RuntimeException('Closure marker missing: '.$m);
$viewRoot=$root.'/app/Interfaces/Web/View'; $pages=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot,FilesystemIterator::SKIP_DOTS)) as $f){
 if(!$f->isFile()||strtolower($f->getExtension())!=='phtml')continue;
 $r=str_replace('\\','/',substr($f->getPathname(),strlen($viewRoot)+1));
 if($r==='index.phtml'||str_starts_with($r,'components/')||str_starts_with($r,'shared/'))continue;
 $pages[]=$r;
}
sort($pages); if($pages!==['property/pdf.phtml'])throw new RuntimeException('Only non-Web property/pdf.phtml may remain: '.implode(', ',$pages));
foreach(['symfony/src/Web/Phtml/PhtmlRenderer.php','app/Interfaces/Web/View/auth/login.phtml','app/Interfaces/Web/View/auth/register.phtml','app/Interfaces/Web/View/diagnostic_report/show.phtml','app/Interfaces/Web/View/error/failure.phtml','app/Interfaces/Web/View/property/presentation.phtml','app/Interfaces/Web/View/spatial/edit.phtml','app/Interfaces/Web/View/spatial/scene.phtml','app/Interfaces/Web/View/shared/spatial_viewer.phtml'] as $p)if(is_file($root.'/'.$p))throw new RuntimeException('Retired artifact restored: '.$p);
foreach(['symfony/templates/experience/public/auth_login.html.twig','symfony/templates/experience/public/auth_register.html.twig','symfony/templates/experience/diagnostic/report.html.twig','symfony/templates/experience/public/property_presentation.html.twig','symfony/templates/experience/spatial/edit.html.twig','symfony/templates/experience/public/spatial_scene.html.twig'] as $p){
 $s=$read($p); foreach(['tn-','onclick=','style='] as $x)if(str_contains($s,$x))throw new RuntimeException('Legacy marker '.$x.' in '.$p);
}
foreach(['symfony/src/Web/Auth/AuthPageController.php','symfony/src/Web/Diagnostic/DiagnosticPageController.php','symfony/src/Web/Property/PropertyPageController.php','symfony/src/Web/Spatial/SpatialPageController.php'] as $p)if(str_contains($read($p),'PhtmlRenderer'))throw new RuntimeException('PHTML ownership restored: '.$p);
$vite=$read('vite.config.js'); if(!str_contains($vite,"'spatial-viewer':")||str_contains($vite,'frontend/entrypoints/'))throw new RuntimeException('Vite must own only specialized Spatial viewer.');
$import=$read('symfony/importmap.php'); foreach(["'app' =>","'public_property' =>","'spatial_admin' =>"] as $m)if(!str_contains($import,$m))throw new RuntimeException('ImportMap missing: '.$m);
$submit=$read('symfony/src/Web/Property/PublicPropertySubmitController.php'); foreach(['CommandBusInterface','SubmitPublicPropertyCommand','HTTP_CREATED'] as $m)if(!str_contains($submit,$m))throw new RuntimeException('Public intake incomplete: '.$m);
echo "Wave 13 final audit: 100% canonical Web ownership passed.\n";
