<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$manifest=require $root.'/app/Domains/ProductManagement/module.php';
$set=[];
foreach($manifest['contributions']['capability_contracts'] as $cap)$set[$cap['id']]=$cap;
foreach(['media.attach'=>'command','media.detach'=>'command','media.search'=>'query'] as $op=>$kind){
    $id='product_management.'.$op;
    $cap=$set[$id]??null;
    if(!is_array($cap)||$cap['kind']!==$kind||$cap['owner_domain']!=='product_management'
        ||$cap['execution_binding']!==$kind.':'.$id
        ||$cap['side_effect_level']!==($kind==='command'?'internal':'none')) {
        throw new RuntimeException('Product Media canonical contract missing or bypassed: '.$id);
    }
}
$adapter=$read('app/Domains/ProductManagement/Infrastructure/Media/ExistingDocumentsImageReference.php');
$store=$read('app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php');
if(!str_contains($adapter,'FROM cos_document_files WHERE organization_id=? AND file_id=?')
   ||!str_contains($adapter,'hash_equals(')||!str_contains($adapter,'getimagesizefromstring(')
   ||!str_contains($store,'product_management.media.attached')
   ||!str_contains($store,'product_management.media.detached')
   ||!str_contains($store,"status='detached'")) {
    throw new RuntimeException('Product Media bypassed tenant-owned files or native events.');
}
echo "Product Management verified Product Media architecture PASS.\n";
