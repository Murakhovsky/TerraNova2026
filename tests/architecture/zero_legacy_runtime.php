<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$production=[
    'app/Kernel','app/Platform','app/Domains','app/Infrastructure',
    'symfony/src','symfony/config','docker','deploy','docker-compose.yml',
    'composer.json','symfony/composer.json',
];
$forbidden=[
    'use Phalcon\\',
    'legacy_cos.pdo',
    'LegacySession',
    'LEGACY_DB_',
    'LEGACY_SESSION_',
    'legacy_backend',
    'legacy_php_sessions',
    'phalconphp/cphalcon',
    '127.0.0.1:8080',
];
$violations=[];
foreach($production as $relative){
    $path=$root.'/'.$relative;
    if(is_file($path)){
        $content=(string)file_get_contents($path);
        foreach($forbidden as $needle) if(str_contains($content,$needle)) $violations[]=$relative.' -> '.$needle;
        continue;
    }
    if(!is_dir($path)) continue;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()) continue;
        $ext=strtolower($file->getExtension());
        if(!in_array($ext,['php','yaml','yml','json','xml','sh','conf','ini','md','txt'],true) && !str_contains($file->getFilename(),'Dockerfile')) continue;
        $content=(string)file_get_contents($file->getPathname());
        foreach($forbidden as $needle){
            if(str_contains($content,$needle)) $violations[]=substr($file->getPathname(),strlen($root)+1).' -> '.$needle;
        }
    }
}
foreach([
    'app/bootstrap_web.php',
    'app/config/services_web.php',
    'app/Interfaces/Web/Module.php',
    'docker/php/Dockerfile',
    'docker/nginx/default.conf',
    'docker-compose.symfony.yml',
    'deploy/symfony-dev.sh',
    'symfony/src/Infrastructure/LegacyConnectionFactory.php',
    'symfony/config/database-cutover-legacy-services.php',
] as $retired){
    if(file_exists($root.'/'.$retired)) $violations[]=$retired.' still exists';
}
if($violations!==[]){
    throw new RuntimeException("Zero-legacy architecture gate failed:\n - ".implode("\n - ",array_values(array_unique($violations))));
}
echo "Zero-legacy architecture gate passed.\n";
