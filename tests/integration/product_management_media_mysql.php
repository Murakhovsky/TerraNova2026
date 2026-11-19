<?php
declare(strict_types=1);

/**
 * Product Media uses verified, tenant-owned COS Documents file records and
 * existing Platform storage. This acceptance runs against real MySQL 8.4.
 */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\ProductMediaService;
use Domains\ProductManagement\Infrastructure\Media\ExistingDocumentsImageReference;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Storage\LocalFileStorage;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
    (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $pass,string $msg):void{
    if(!$pass)throw new RuntimeException('Product Media MySQL: '.$msg);
};
$reject=static function(callable $fn,string $reason)use($assert):void{
    try{$fn();}catch(DomainException){return;}
    $assert(false,$reason);
};
$token=bin2hex(random_bytes(8));
$org='pm-media-'.$token;
$other='pm-media-other-'.$token;
$fileId='FILE-'.strtoupper($token);
$rootFolder=sys_get_temp_dir().'/cos-media-test-'.$token;
$storage=new LocalFileStorage($rootFolder);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5WQAAAABJRU5ErkJggg==',true);
$assert(is_string($png)&&@getimagesizefromstring($png)!==false,'test PNG fixture missing');
$key='documents/'.substr(hash('sha256',$org),0,20).'/DOC-'.$token.'/'.$fileId.'/tiny.png';
$uploaded=$storage->put($key,$png,'image/png');
$pdo->prepare('INSERT INTO cos_document_files
 (organization_id,file_id,storage_key,mime_type,size_bytes,sha256,created_by)
 VALUES (?,?,?,?,?,?,?)')->execute([
    $org,$fileId,$uploaded->key,'image/png',$uploaded->size,$uploaded->sha256,42,
]);
$store=new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo));
$products=new ProductManagementService($store);
$media=new ProductMediaService($store,new ExistingDocumentsImageReference($pdo,$storage));
$p=$products->createProduct($org,['sku'=>'MEDIA'.strtoupper($token),'name'=>'Media Product','type'=>'stock'],
    'pm-media-product-'.$token,'42','ci-media');
$pid=$p['product_id'];
$otherProduct=$products->createProduct($org,['sku'=>'OTHER'.strtoupper($token),'name'=>'Other Product','type'=>'stock'],
    'pm-media-other-product-'.$token,'42','ci-media');
$reject(static fn()=> $media->attach($other,$pid,$fileId,null,'gallery',10,
    'pm-cross-'.$token,'43','ci-media'),'cross-tenant image source must be rejected');
$reject(static fn()=> $media->attach($org,$pid,$fileId,$otherProduct['variant_id'],'gallery',10,
    'pm-cross-variant-'.$token,'42','ci-media'),'wrong Product variant relation was accepted');
$attached=$media->attach($org,$pid,$fileId,$p['variant_id'],'cover',10,
    'pm-attach-'.$token,'42','ci-media');
$replayed=$media->attach($org,$pid,$fileId,$p['variant_id'],'cover',10,
    'pm-attach-'.$token,'42','ci-media');
$assert($attached['media_id']===$replayed['media_id'],'idempotency did not preserve media ID');
$assert(count($media->search($org,$pid))===1,'one active media link required');
$projection=$products->getProduct($org,$pid);
$assert(count($projection['media'])===1 && $projection['media'][0]['file_id']===$fileId,
    'Product read must return safe media metadata');
$assert(!array_key_exists('storage_ref',$projection['media'][0]),
    'Internal Platform storage key leaked through Product read');
$reject(static fn()=> $media->attach($org,$pid,$fileId,$p['variant_id'],'gallery',20,
    'pm-duplicate-'.$token,'42','ci-media'),'duplicate active file relation was accepted');
$reject(static fn()=> $media->attach($org,$pid,$fileId,$p['variant_id'],'cover',12,
    'pm-attach-'.$token,'42','ci-media'),'media key was reused with changed payload');
$reject(static fn()=> $media->detach($other,$attached['media_id'],
    'pm-cross-remove-'.$token,'43','ci-media'),'cross-tenant media unlink was accepted');
$detached=$media->detach($org,$attached['media_id'],'pm-detach-'.$token,'42','ci-media');
$detachReplay=$media->detach($org,$attached['media_id'],'pm-detach-'.$token,'42','ci-media');
$assert($detached['status']==='detached' && $detachReplay['media_id']===$detached['media_id'],
    'soft detach idempotency must preserve native relation');
$assert($media->search($org,$pid)===[] && $storage->exists($key),
    'detach may not delete Platform Documents bytes');
$storage->put($key,'INVALID-CONTENTS','image/png');
$reject(static fn()=> $media->attach($org,$pid,$fileId,null,'gallery',5,
    'pm-changed-bytes-'.$token,'42','ci-media'),
    'image checksum changed after Documents file registration');
$stmt=$pdo->prepare("SELECT type,aggregate_id FROM cos_events WHERE organization_id=? AND type IN
 ('product_management.media.attached','product_management.media.detached')");
$stmt->execute([$org]);$events=$stmt->fetchAll(PDO::FETCH_ASSOC);
$assert(count($events)===2,'Attach and detach must each emit one native Domain event');
$assert($events[0]['aggregate_id']===$pid&&$events[1]['aggregate_id']===$pid,
    'Media events must reference Product aggregate');
$stmt=$pdo->prepare("SELECT COUNT(*) FROM cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id
 WHERE e.organization_id=? AND e.type IN
 ('product_management.media.attached','product_management.media.detached')");
$stmt->execute([$org]);
$assert((int)$stmt->fetchColumn()===2,'Media Event/Outbox must be committed together');
$storage->delete($key);
echo "Product Media verified Documents image links and tenant isolation MySQL PASS.\n";
