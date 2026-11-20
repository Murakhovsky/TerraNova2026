<?php
declare(strict_types=1);

/**
 * Real MySQL crash-point fixture for unknown Shopify native Action outcomes.
 * No Shopify calls, direct stock postings, automatic replays or fake green receipts.
 */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Automation\Action\ShopifyImportApplyHandler;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportPreviewService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportRecoveryAudit;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
    (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$token=bin2hex(random_bytes(7));$org='pm-crash-'.$token;
$events=new MysqlEventStore($pdo);
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,$events));
$pricing=new PricingService($pdo,$events);
$observations=new ShopifyObservationStore($pdo);
$states=new class implements ModuleStateRepositoryInterface {
    public function enabledOverride(string $org,string $moduleId):?bool {
        return $moduleId==='product_management'?true:null;
    }
    public function setEnabled(string $org,string $moduleId,bool $enabled):void{}
};
$catalog=new ModuleCatalog((new ModuleDiscovery(dirname(__DIR__,2).'/app/Domains'))->discover());
$modules=new ActiveModuleResolver($catalog,$states);
$staging=new ShopifyImportPreviewService($pdo,$observations,$modules);
$writer=new ShopifyImportApplyService($pdo,$products,$pricing,$observations);
$audit=new ShopifyImportRecoveryAudit($pdo,$observations,$writer);
$handler=new ShopifyImportApplyHandler($writer,$modules);
$conn=$observations->provision($org,'pm-crash-test.myshopify.com','shopify_env_primary');
$observations->recordShopCurrency($org,$conn,'USD');

$assert=static function(bool $true,string $why):void {
    if(!$true)throw new RuntimeException('Shopify native crash recovery: '.$why);
};
$query=static function(string $sql,array $values=[])use($pdo):array {
    $stmt=$pdo->prepare($sql);$stmt->execute($values);return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$count=static function(string $table)use($query,$org):int {
    return count($query('SELECT organization_id FROM '.$table.' WHERE organization_id=?',[$org]));
};
$outboxCount=static function()use($query,$org):int {
    return count($query('SELECT o.event_id FROM cos_event_outbox o
        INNER JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=?',[$org]));
};
$source=static function(int $seed,string $a,string $b):array {
    return [
        'id'=>'gid://shopify/Product/'.$seed,
        'title'=>'Crash point '.$seed,'status'=>'ACTIVE',
        'variants'=>['nodes'=>[
            ['id'=>'gid://shopify/ProductVariant/'.($seed+1),
             'sku'=>$a,'price'=>'20.10','barcode'=>null,
             'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/'.($seed+2),
                 'tracked'=>true,'inventoryLevels'=>['nodes'=>[],
                     'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
            ['id'=>'gid://shopify/ProductVariant/'.($seed+3),
             'sku'=>$b,'price'=>'25.20','barcode'=>null,
             'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/'.($seed+4),
                 'tracked'=>false,'inventoryLevels'=>['nodes'=>[],
                     'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
        ],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]],
    ];
};
$stage=static function(int $seed)use($token,$org,$conn,$source,$observations,$staging):array {
    $a='CRASH'.strtoupper($token).'A'.$seed;
    $b='CRASH'.strtoupper($token).'B'.$seed;
    $snapshot=$source($seed,$a,$b);
    $observations->observeProduct($org,$conn,$snapshot['id'],$snapshot,true);
    $hash=$observations->productSnapshot($org,$conn,$snapshot['id'])['payload_hash'];
    $preview=$staging->stage($org,$conn,$snapshot['id'],'42',$hash);
    $preview['source']=$snapshot;
    $preview['sku_a']=$a;
    $preview['sku_b']=$b;
    return $preview;
};
$action=static function(array $preview)use($pdo,$org):Action {
    $previewId=$preview['preview_id'];
    $key='wf:'.hash('sha256',$org.':'.$previewId);
    $pdo->prepare("UPDATE pm_shopify_import_previews
        SET goal_id=?,plan_id=?,state='applying',action_key=?
        WHERE organization_id=? AND preview_id=? AND state='staged'")
        ->execute(['goal-'.$previewId,'plan-'.$previewId,$key,$org,$previewId]);
    return new Action(bin2hex(random_bytes(16)),$org,ShopifyImportApplyHandler::TYPE,
        'shopify_import_preview',$previewId,
        ['source_hash'=>$preview['source_hash'],'normalized_digest'=>$preview['normalized_digest']],
        'USER','42','APPROVAL_REQUIRED','HIGH',$key,
        new DateTimeImmutable(),ActionStatus::Running,'pm-crash-fixture');
};

$noEffects=$stage(69001);
$initial=$audit->inspect($org,$conn,$noEffects['preview_id']);
$assert($initial['decision']==='staged_no_native_effects'
    &&!$initial['native_side_effects_detected']
    &&$initial['automatic_replay_authorized']===false,
    'Read-only audit did not distinguish unstarted candidate.');
$firstAction=$action($noEffects);
$assert(!$handler->execute($firstAction)->successful,
    'Worker reentered applying with no native receipt and silently proceeded.');
$firstReport=$audit->inspect($org,$conn,$noEffects['preview_id']);
$assert($firstReport['state']==='blocked'
    &&$firstReport['decision']==='manual_reconciliation_required'
    &&!$firstReport['native_side_effects_detected'],
    'Uncertain previous attempt must be quarantined even without native effects.');
$assert(!$handler->execute($firstAction)->successful,
    'Blocked Action was resurrected without independent review.');

$partial=$stage(69011);
$secondAction=$action($partial);
$productKey='pm-shopify:'.hash('sha256',$org.':'.$partial['preview_id'].':product');
$created=$products->createProduct($org,[
    'name'=>'Crash point 69011','sku'=>$partial['sku_a'],
    'type'=>'stock','uom'=>'pcs','track_inventory'=>true,
],$productKey,'42','ci-crash-before-receipt');
$assert($count('pm_products')===1&&$count('pm_variants')===1,
    'Product crash fixture failed to commit the native Product receipt.');
$beforeEvents=$count('cos_events');$beforeOutbox=$outboxCount();
$assert(!$handler->execute($secondAction)->successful,
    'Replayed a previous Action after native Product creation.');
$partialReport=$audit->inspect($org,$conn,$partial['preview_id']);
$assert($partialReport['state']==='blocked'
    &&$partialReport['native_side_effects_detected']===true
    &&$partialReport['native_product_receipt_present']===true
    &&$partialReport['native_product_matches']===true
    &&$partialReport['verified_variant_steps']===0
    &&$partialReport['variant_steps'][0]['native_variant_present']===true
    &&$partialReport['variant_steps'][0]['native_price_matches']===false
    &&$partialReport['variant_steps'][1]['native_variant_present']===false,
    'Partial Product creation was falsely attested as complete.');
$assert($count('pm_products')===1&&$count('pm_variants')===1
    &&$count('cos_events')===$beforeEvents
    &&$outboxCount()===$beforeOutbox
    &&$count('pm_stock_movements')===0,
    'Interrupted Action replay duplicated business effects.');

// A new provider snapshot for the SAME GID may be staged for human review,
// but cannot acquire a second independent Goal/Plan while an older attempt
// remains blocked with unknown native Product effects.
$changedIdentity=$partial['source'];
$changedIdentity['variants']['nodes'][0]['sku']='RENEW'.strtoupper($token).'A';
$changedIdentity['variants']['nodes'][1]['sku']='RENEW'.strtoupper($token).'B';
$observations->observeProduct($org,$conn,$changedIdentity['id'],$changedIdentity,true);
$updatedHash=$observations->productSnapshot($org,$conn,$changedIdentity['id'])['payload_hash'];
$newVersion=$staging->stage($org,$conn,$changedIdentity['id'],'42',$updatedHash);
$assert($newVersion['preview_id']!==$partial['preview_id'],
    'New Shopify snapshot must preserve a separate immutable review candidate.');
$secondPlanDenied=false;
try {
    $pdo->prepare('UPDATE pm_shopify_import_previews SET plan_id=?,goal_id=?
        WHERE organization_id=? AND preview_id=?')
        ->execute(['plan-conflict-'.$token,'goal-conflict-'.$token,$org,$newVersion['preview_id']]);
}catch(PDOException $e) {
    $secondPlanDenied=$e->getCode()==='23000';
    if(!$secondPlanDenied)throw $e;
}
$assert($secondPlanDenied
    &&$query('SELECT plan_id FROM pm_shopify_import_previews
        WHERE organization_id=? AND preview_id=?',[$org,$newVersion['preview_id']])[0]['plan_id']===null,
    'Second Plan illegally claimed a Shopify Product with blocked native effects.');
$assert($count('pm_products')===1,
    'Second Shopify identity claim duplicated Product during blocked recovery.');

$partialPrice=$stage(69021);
$thirdAction=$action($partialPrice);
$created2=$products->createProduct($org,[
    'name'=>'Crash point 69021','sku'=>$partialPrice['sku_a'],
    'type'=>'stock','uom'=>'pcs','track_inventory'=>true,
], 'pm-shopify:'.hash('sha256',$org.':'.$partialPrice['preview_id'].':product'),
    '42','ci-crash-price');
$pricing->set($org,$created2['variant_id'],'USD','20.10',
    'pm-shopify:'.hash('sha256',$org.':'.$partialPrice['preview_id'].':price:0'),
    '42','ci-crash-price');
$observations->map($org,$conn,'variant',
    $partialPrice['source']['variants']['nodes'][0]['id'],$created2['variant_id']);
$eventSnapshot=$count('cos_events');
$assert(!$handler->execute($thirdAction)->successful,
    'Partially priced and mapped Action was replayed.');
$priced=$audit->inspect($org,$conn,$partialPrice['preview_id']);
$assert($priced['state']==='blocked'
    &&$priced['variant_steps'][0]['native_price_matches']===true
    &&$priced['variant_steps'][0]['native_price_receipt_present']===true
    &&$priced['variant_steps'][0]['native_step_receipt_matches']===false
    &&$priced['verified_variant_steps']===0,
    'Audit misreported a price write without the final mapped-step receipt.');
$assert($count('cos_events')===$eventSnapshot
    &&$count('pm_base_price_history')===1
    &&$count('pm_products')===2,
    'Crash replay duplicated Price events or Product identities.');

$drift=$stage(69031);
$change=$drift['source'];
$change['variants']['nodes'][0]['price']='21.10';
$observations->observeProduct($org,$conn,$change['id'],$change,true);
$report=$audit->inspect($org,$conn,$drift['preview_id']);
$assert($report['decision']==='manual_reconciliation_required'
    &&$report['source_hash_matches_current']===false
    &&!$report['native_side_effects_detected'],
    'Shopify price drift was not flagged before an approved Action.');

try{
    $audit->inspect('foreign-'.$token,$conn,$partial['preview_id']);
    throw new RuntimeException('Foreign-tenant recovery audit escaped ownership fence.');
}catch(DomainException){}
try{
    $audit->inspect($org,str_repeat('e',32),$partial['preview_id']);
    throw new RuntimeException('Wrong-connection recovery audit escaped ownership fence.');
}catch(DomainException){}

echo "Product Management Shopify crash quarantine and read-only audit MySQL passed.\n";
