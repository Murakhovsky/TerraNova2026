<?php
declare(strict_types=1);
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportCandidateNormalizer;
$raw=[
    'id'=>'gid://shopify/Product/101','title'=>'Pilot Remote','status'=>'ACTIVE',
    'variants'=>['nodes'=>[
        ['id'=>'gid://shopify/ProductVariant/201','sku'=>'sku-201','price'=>'9.25',
         'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/301','tracked'=>true,
           'inventoryLevels'=>['pageInfo'=>['hasNextPage'=>false]]]],
        ['id'=>'gid://shopify/ProductVariant/202','sku'=>'sku-202','price'=>'11.00',
         'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/302','tracked'=>false,
           'inventoryLevels'=>['pageInfo'=>['hasNextPage'=>false]]]],
    ],'pageInfo'=>['hasNextPage'=>false]],
];
$normalized=ShopifyImportCandidateNormalizer::normalize($raw,'USD');
if($normalized['name']!=='Pilot Remote'||count($normalized['variants'])!==2
  ||$normalized['variants'][0]['sku']!=='SKU-201'
  ||$normalized['variants'][1]['track_inventory']!==false
  ||$normalized['variants'][0]['currency']!=='USD') {
    throw new RuntimeException('Shopify normalized import preview lost canonical Product facts.');
}
foreach([
    static function(array &$r):void{$r['variants']['pageInfo']['hasNextPage']=true;},
    static function(array &$r):void{$r['variants']['nodes'][1]['sku']='SKU-201';},
    static function(array &$r):void{$r['variants']['nodes'][1]['price']='-1';},
    static function(array &$r):void{$r['variants']['nodes'][1]['id']='gid://shopify/ProductVariant/201';},
    static function(array &$r):void{$r['variants']['nodes'][0]['inventoryItem']['inventoryLevels']['pageInfo']['hasNextPage']=true;},
    static function(array &$r):void{$r['status']='ARCHIVED';},
] as $mutate) {
    $candidate=$raw;$mutate($candidate);
    try {
        ShopifyImportCandidateNormalizer::normalize($candidate,'USD');
        throw new RuntimeException('Invalid Shopify candidate bypassed validation.');
    } catch (DomainException $e) { /* expected */ }
}
try {
    ShopifyImportCandidateNormalizer::normalize($raw,'BAD4');
    throw new RuntimeException('Invalid shop currency accepted.');
} catch(DomainException $e) {}
echo "Product Management Shopify candidate normalization passed.\n";
