<?php
declare(strict_types=1);

/** Read-only Shopify candidate staging on real MySQL; never mutates canonical catalog. */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportPreviewService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

$pdo=new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
       (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
       (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),
    (string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$tag=bin2hex(random_bytes(6));$org='pm-stage-'.$tag;
$foreign='pm-other-'.$tag;$shop='pm-staging-fixture.myshopify.com';
$sku='STAGE'.strtoupper($tag);
$state=new class implements ModuleStateRepositoryInterface {
    public array $enabled=[];
    public function enabledOverride(string $org,string $moduleId):?bool {
        return $moduleId==='product_management'?($this->enabled[$org]??false):null;
    }
    public function setEnabled(string $org,string $moduleId,bool $enabled):void {
        if($moduleId==='product_management')$this->enabled[$org]=$enabled;
    }
};
$catalog=new ModuleCatalog((new ModuleDiscovery(dirname(__DIR__,2).'/app/Domains'))->discover());
$modules=new ActiveModuleResolver($catalog,$state);
$observation=new ShopifyObservationStore($pdo);
$preview=new ShopifyImportPreviewService($pdo,$observation,$modules);
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
$connection=$observation->provision($org,$shop,'shopify_env_primary');
$gid='gid://shopify/Product/5501';
$raw=[
    'id'=>$gid,'title'=>'Observed Product','status'=>'ACTIVE',
    'variants'=>['nodes'=>[[
        'id'=>'gid://shopify/ProductVariant/5511',
        'sku'=>$sku,'price'=>'23.95','barcode'=>null,
        'inventoryItem'=>[
            'id'=>'gid://shopify/InventoryItem/5521','tracked'=>true,
            'inventoryLevels'=>['nodes'=>[[
                'location'=>['id'=>'gid://shopify/Location/5531','name'=>'Warehouse'],
                'quantities'=>[['name'=>'on_hand','quantity'=>3],['name'=>'available','quantity'=>2]],
            ]],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]],
        ],
    ]],'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]],
];
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('Shopify MySQL preview: '.$why);};
$denied=static function(callable $fn)use($check):void {
    try{$fn();throw new RuntimeException('Dangerous Shopify candidate admitted.');}
    catch(DomainException $expected){/* expected */}
};
$observation->observeProduct($org,$connection,$gid,$raw); // Raw, incomplete.
$hash=$observation->productSnapshot($org,$connection,$gid)['payload_hash'];
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',$hash));
$state->setEnabled($org,'product_management',true);
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',$hash));
$observation->recordShopCurrency($org,$connection,'USD');
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',$hash));
$observation->observeProduct($org,$connection,$gid,$raw,true);
$stage=$preview->stage($org,$connection,$gid,'operator',$hash);
$check($stage['status']==='staged_only'&&$stage['currency']==='USD'
    &&count($stage['variants'])===1
    &&$stage['variants'][0]['sku']===$sku
    &&$stage['variants'][0]['price']==='23.95',
    'Source facts were not normalized into a pinned candidate.');
$again=$preview->stage($org,$connection,$gid,'operator',$hash);
$check($again['preview_id']===$stage['preview_id'],
    'Repeat identical staging must retain immutable identity.');
$count=$pdo->prepare('SELECT COUNT(*) FROM pm_shopify_import_previews WHERE organization_id=?');
$count->execute([$org]);
$check((int)$count->fetchColumn()===1,'Staged snapshot duplicated.');
$check($products->searchProducts($org,$sku)===[],'Read-only stage created a native Product.');
$check($observation->mapping($org,$connection,'product',$gid)===null,'Staging approved an external identity.');
$state->setEnabled($foreign,'product_management',true);
$denied(static fn()=>$preview->stage($foreign,$connection,$gid,'operator',$hash));
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',str_repeat('0',64)));
$changed=$raw;
$changed['variants']['nodes'][0]['price']='24.95';
$observation->observeProduct($org,$connection,$gid,$changed,true);
$newHash=$observation->productSnapshot($org,$connection,$gid)['payload_hash'];
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',$hash));
$new=$preview->stage($org,$connection,$gid,'operator',$newHash);
$check($new['preview_id']!==$stage['preview_id'] &&
    $new['normalized_digest']!==$stage['normalized_digest'],
    'Provider price drift must generate new, separately reviewable candidate.');
$original=$pdo->prepare('SELECT source_hash,normalized_digest,normalized_json FROM pm_shopify_import_previews
    WHERE organization_id=? AND preview_id=?');
$original->execute([$org,$stage['preview_id']]);
$old=$original->fetch(PDO::FETCH_ASSOC);
$oldNormalized=json_decode((string)$old['normalized_json'],true,flags:JSON_THROW_ON_ERROR);
$check($old['source_hash']===$hash&&$old['normalized_digest']===$stage['normalized_digest']
    &&$oldNormalized['variants'][0]['price']==='23.95',
    'Provider change overwrote the frozen preview.');
$products->createProduct($org,['name'=>'Existing Canonical','sku'=>$sku,'type'=>'stock'],
    'fixture-'.$tag,'42','pm-stage-dup');
$denied(static fn()=>$preview->stage($org,$connection,$gid,'operator',$newHash));
$check($observation->mapping($org,$connection,'product',$gid)===null,
    'Conflicting duplicate SKU stage silently approved a mapping.');
echo "Product Management Shopify immutable read-only preview MySQL passed.\n";
