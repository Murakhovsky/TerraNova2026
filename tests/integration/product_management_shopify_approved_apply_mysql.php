<?php
declare(strict_types=1);

/**
 * Isolated real MySQL writer acceptance. The Action fixture models the
 * already-admitted canonical worker boundary; Plan/Approval/Run E2E is separate.
 * No Shopify API calls or stock movements are allowed.
 */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Automation\Action\ShopifyImportApplyHandler;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportPreviewService;
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
$events=new MysqlEventStore($pdo);
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,$events));
$pricing=new PricingService($pdo,$events);
$observations=new ShopifyObservationStore($pdo);
$state=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $org,string $moduleId):?bool {
        return $moduleId==='product_management'?$this->enabled:null;
    }
    public function setEnabled(string $org,string $moduleId,bool $value):void{$this->enabled=$value;}
};
$catalog=new ModuleCatalog((new ModuleDiscovery(dirname(__DIR__,2).'/app/Domains'))->discover());
$modules=new ActiveModuleResolver($catalog,$state);
$staging=new ShopifyImportPreviewService($pdo,$observations,$modules);
$writer=new ShopifyImportApplyService($pdo,$products,$pricing,$observations);
$handler=new ShopifyImportApplyHandler($writer,$modules);
$token=bin2hex(random_bytes(6));$org='pm-apply-'.$token;$shop='pm-apply-ci.myshopify.com';
$connection=$observations->provision($org,$shop,'shopify_env_primary');
$gid='gid://shopify/Product/881';
$skuA='APPLY'.strtoupper($token).'A';$skuB='APPLY'.strtoupper($token).'B';
$source=[
    'id'=>$gid,'title'=>'Approved Shopify Item','status'=>'ACTIVE',
    'variants'=>['nodes'=>[
        ['id'=>'gid://shopify/ProductVariant/882','sku'=>$skuA,'price'=>'12.25','barcode'=>'BARCODE882',
         'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/887','tracked'=>true,
             'inventoryLevels'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
        ['id'=>'gid://shopify/ProductVariant/883','sku'=>$skuB,'price'=>'7.10','barcode'=>null,
         'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/888','tracked'=>false,
             'inventoryLevels'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]]]],
    ],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]],
];
$assert=static function(bool $yes,string $why):void{
    if(!$yes)throw new RuntimeException('Approved Shopify MySQL: '.$why);
};
$observations->recordShopCurrency($org,$connection,'USD');
$observations->observeProduct($org,$connection,$gid,$source,true);
$hash=$observations->productSnapshot($org,$connection,$gid)['payload_hash'];
$preview=$staging->stage($org,$connection,$gid,'operator',$hash);
$previewId=$preview['preview_id'];$digest=$preview['normalized_digest'];
$pdo->prepare('UPDATE pm_shopify_import_previews SET goal_id=?,plan_id=? WHERE organization_id=? AND preview_id=?')
    ->execute(['goal-fixture-'.$token,'plan-fixture-'.$token,$org,$previewId]);
$actionKey='wf:'.hash('sha256',$org.':'.$previewId);
$action=new Action(bin2hex(random_bytes(16)),$org,ShopifyImportApplyHandler::TYPE,
    'shopify_import_preview',$previewId,
    ['source_hash'=>$hash,'normalized_digest'=>$digest],
    'USER','42','APPROVAL_REQUIRED','HIGH',$actionKey,
    new DateTimeImmutable(),ActionStatus::Running,'pm-shopify-ci');
$state->enabled=false;
$assert(!$handler->execute($action)->successful,'Disabled tenant could import.');
$state->enabled=true;
$assert($products->searchProducts($org,$skuA)===[],'Preview already wrote a Product.');
$committed=$handler->execute($action);
$assert($committed->successful,'Approved Shopify canonical materialization failed.');
$result=$committed->data;
$assert($result['verified_variants']===2 && $result['state']==='applied'
    &&count($result['variant_ids'])===2,'Approved action lost Variants.');
$product=$products->getProduct($org,$result['product_id']);
$assert($product!==null&&$product['name']==='Approved Shopify Item'
    &&count($product['variants'])===2,'Native Product or Variants were not created.');
$assert($observations->mapping($org,$connection,'product',$gid)['canonical_id']===$result['product_id'],
    'Approved Product mapping missing.');
foreach(['gid://shopify/ProductVariant/882','gid://shopify/ProductVariant/883'] as $i=>$variantGid) {
    $assert($observations->mapping($org,$connection,'variant',$variantGid)['canonical_id']===$result['variant_ids'][$i],
        'Approved Variant GID mapping missing.');
}
$assert((string)$pricing->get($org,$result['variant_ids'][0],'USD')['price']==='12.2500'
    &&(string)$pricing->get($org,$result['variant_ids'][1],'USD')['price']==='7.1000',
    'Native Base Prices were not persisted.');
$assert($writer->verifyApplied($org,$previewId,$hash,$digest)['variant_ids']===$result['variant_ids'],
    'Independent native receipt verification differs.');
$replay=$handler->execute($action);
$assert($replay->successful&&$replay->data['variant_ids']===$result['variant_ids'],
    'Approved replay duplicated native Variants.');
$checkCount=static function(string $table)use($pdo,$org):int {
    $st=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE organization_id=?');$st->execute([$org]);
    return (int)$st->fetchColumn();
};
$assert($checkCount('pm_products')===1
    &&$checkCount('pm_variants')===2
    &&$checkCount('pm_base_prices')===2
    &&$checkCount('pm_base_price_history')===2
    &&$checkCount('pm_stock_movements')===0
    &&$checkCount('pm_shopify_import_step_receipts')===2,
    'Replay duplicated Product/Price/stock effects.');
$forged=new Action(bin2hex(random_bytes(16)),$org,ShopifyImportApplyHandler::TYPE,
    'shopify_import_preview',$previewId,
    ['source_hash'=>str_repeat('0',64),'normalized_digest'=>$digest],
    'USER','42','APPROVAL_REQUIRED','HIGH','wf:'.hash('sha256','forged'.$token),
    new DateTimeImmutable(),ActionStatus::Running,'pm-shopify-forged');
$assert(!$handler->execute($forged)->successful,'Forged approved source digest admitted.');
$state->enabled=false;
$assert(!$handler->execute($action)->successful,'Re-disabled tenant replay was accepted.');
echo "Product Management Shopify approved native MySQL materialization passed.\n";
