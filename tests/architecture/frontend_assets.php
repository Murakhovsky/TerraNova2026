<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/symfony/src/Web/Phtml/ViteAssetManifest.php';

use App\Web\Phtml\ViteAssetManifest;

$root=dirname(__DIR__,2);
$manifestPath=$root.'/public/build/.vite/manifest.json';
$entries=[
    'analytics-workspace',
    'cos-site','cos-ui-runtime','portal-cabinet',
    'public-surface','terranova-catalog-api','terranova-copy','terranova-interface',
    'terranova-media-manager','terranova-property-gallery','terranova-spatial-admin','spatial-viewer',
];

foreach([$root.'/public/js',$root.'/public/css',$root.'/public/assets/js',$root.'/public/assets/css'] as $legacyDirectory){
    if(is_dir($legacyDirectory))throw new RuntimeException('Retired browser source directory restored: '.$legacyDirectory);
}
if(!is_dir($root.'/frontend'))throw new RuntimeException('Frontend source must live under /frontend.');

$viewRoot=$root.'/app/Interfaces/Web/View';
$exceptions=['app/Interfaces/Web/View/property/pdf.phtml'];
$views=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot,FilesystemIterator::SKIP_DOTS));
foreach($views as $view){
    if(!$view->isFile()||strtolower($view->getExtension())!=='phtml')continue;
    $relative=str_replace('\\','/',substr($view->getPathname(),strlen($root)+1));
    if(in_array($relative,$exceptions,true))continue;
    $source=(string)file_get_contents($view->getPathname());
    if(preg_match('/<style\b/i',$source)===1)throw new RuntimeException('Inline CSS is forbidden: '.$relative);
    $scan=preg_replace('/<\?php\b.*?\?>/s','PHP_EXPR',$source);
    if(preg_match_all('/<script\b([^>]*)>/i',(string)$scan,$scripts,PREG_SET_ORDER)){
        foreach($scripts as $script){
            if(preg_match('/\btype\s*=\s*["\']application\/(?:ld\+json|json)["\']/i',$script[1])===1)continue;
            if(preg_match('/\bsrc\s*=\s*["\'][^"\']+["\']/i',$script[1])===1)continue;
            throw new RuntimeException('Inline browser JavaScript is forbidden: '.$relative);
        }
    }
}

$assets=(new ViteAssetManifest($manifestPath))->assets($entries);
if(count($assets['scripts'])!==count($entries))throw new RuntimeException('Not every Vite entrypoint is present.');
foreach(array_merge($assets['scripts'],$assets['styles']) as $url){
    $path=$root.'/public/'.ltrim((string)parse_url($url,PHP_URL_PATH),'/');
    if(!is_file($path))throw new RuntimeException('Manifest asset missing: '.$path);
}
echo "Frontend assets passed on canonical Symfony renderer.\n";
