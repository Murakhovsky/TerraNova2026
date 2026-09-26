<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

require $root.'/tests/architecture/zero_legacy_runtime.php';

$read=static function(string $path)use($root):string{
    $full=$root.'/'.$path;
    if(!is_file($full))throw new RuntimeException('Production artifact missing: '.$path);
    return (string)file_get_contents($full);
};

foreach([
    'symfony/src/Web/Phtml/PhtmlRenderer.php',
    'symfony/src/Web/Phtml/ViteAssetManifest.php',
    'symfony/src/Web/Navigation/NavigationBuilder.php',
    'app/Interfaces/Web/View/error/failure.phtml',
    'frontend/core/production.js',
    'frontend/styles/production.css',
    'docker-compose.yml',
] as $path)$read($path);

foreach(['app/Domains/Frontend','app/Domains/Public','app/Domains/Portal'] as $forbidden){
    if(is_dir($root.'/'.$forbidden))throw new RuntimeException('Frontend presentation must not become a business domain: '.$forbidden);
}

$design=$read('frontend/styles/design-system.css');
foreach(['tokens.css','foundation.css','components.css','patterns.css','production.css'] as $needle){
    if(!str_contains($design,$needle))throw new RuntimeException('Design system dependency missing: '.$needle);
}

$production=$read('frontend/styles/production.css');
foreach(['min-height: 44px','@media (prefers-reduced-motion: reduce)'] as $needle){
    if(!str_contains($production,$needle))throw new RuntimeException('Production accessibility contract missing: '.$needle);
}

$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/frontend',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
    if(!$file->isFile()||strtolower($file->getExtension())!=='js')continue;
    $source=(string)file_get_contents($file->getPathname());
    foreach(['localStorage','sessionStorage'] as $forbidden){
        if(str_contains($source,$forbidden))throw new RuntimeException('Browser persistence cannot be canonical state: '.$file->getPathname().' -> '.$forbidden);
    }
}

$manifestPath=$root.'/public/build/.vite/manifest.json';
if(!is_file($manifestPath))throw new RuntimeException('Vite manifest is required.');
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
foreach(['frontend/entrypoints/public-surface.js','frontend/entrypoints/terranova-interface.js'] as $entry){
    if(!isset($manifest[$entry]))throw new RuntimeException('Canonical Vite entry missing: '.$entry);
}

echo "Frontend production closure passed on Symfony-only runtime.\n";
