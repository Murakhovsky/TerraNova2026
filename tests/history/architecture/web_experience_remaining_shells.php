<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $p):string=>is_file($root.'/'.$p)?(string)file_get_contents($root.'/'.$p):throw new RuntimeException('Missing final shell artifact: '.$p);
$surfaces=[
 'symfony/templates/experience/public/auth_login.html.twig'=>['<twig:CosPageHeader','<twig:CosFormSection','name="email"'],
 'symfony/templates/experience/public/auth_register.html.twig'=>['<twig:CosPageHeader','name="password_repeat"'],
 'symfony/templates/experience/diagnostic/report.html.twig'=>['<twig:CosPageHeader','cos-kpi-strip'],
 'symfony/templates/experience/public/property_presentation.html.twig'=>['data-cos-public="property-presentation"','id="request"'],
 'symfony/templates/experience/spatial/edit.html.twig'=>['data-spatial-upload',"importmap('spatial_admin')"],
 'symfony/templates/experience/public/spatial_scene.html.twig'=>['data-spatial-viewer','islandAssets.scripts'],
];
foreach($surfaces as $p=>$markers){$s=$read($p);foreach($markers as $m)if(!str_contains($s,$m))throw new RuntimeException($p.' missing '.$m);foreach(['tn-','onclick=','style='] as $x)if(str_contains($s,$x))throw new RuntimeException($p.' restored '.$x);}
$controllers=[
 'symfony/src/Web/Auth/AuthPageController.php'=>['Environment','PageArchetype::FormEditor'],
 'symfony/src/Web/Diagnostic/DiagnosticPageController.php'=>['GetDiagnosticReportQuery','WorkspaceShellFactory'],
 'symfony/src/Web/Property/PropertyPageController.php'=>['GetPublicPropertyPresentationQuery','CommandBusInterface'],
 'symfony/src/Web/Spatial/SpatialPageController.php'=>['WorkspaceShellFactory','ViteAssetManifest'],
];
foreach($controllers as $p=>$markers){$s=$read($p);foreach($markers as $m)if(!str_contains($s,$m))throw new RuntimeException($p.' missing '.$m);if(str_contains($s,'PhtmlRenderer'))throw new RuntimeException($p.' restored PHTML');}
echo "Remaining shell closure: all Web shells are canonical Twig.\n";
