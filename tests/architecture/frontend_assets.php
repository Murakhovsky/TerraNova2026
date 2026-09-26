<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/symfony/src/Web/Experience/Asset/ViteAssetManifest.php';
use App\Web\Experience\Asset\ViteAssetManifest;
$root=dirname(__DIR__,2); $manifest=$root.'/public/build/.vite/manifest.json';
$vite=(string)file_get_contents($root.'/vite.config.js');
if(str_contains($vite,'frontend/entrypoints/'))throw new RuntimeException('Generic Vite entrypoints retired.');
if(!str_contains($vite,"'spatial-viewer':"))throw new RuntimeException('Spatial viewer island missing.');
$a=(new ViteAssetManifest($manifest))->assets(['spatial-viewer']); if(count($a['scripts'])!==1)throw new RuntimeException('Spatial viewer build missing.');
foreach(array_merge($a['scripts'],$a['styles']) as $u){$p=$root.'/public/'.ltrim((string)parse_url($u,PHP_URL_PATH),'/');if(!is_file($p))throw new RuntimeException('Built asset missing: '.$p);}
echo "Frontend assets: AssetMapper canonical, Vite isolated to Spatial.\n";
