<?php
declare(strict_types=1);

require_once __DIR__.'/../../app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyWebhookVerifier.php';
require_once __DIR__.'/../../app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyOrderWebhookEvidence.php';

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyOrderWebhookEvidence as OrderPointer;

$secret='test-local-not-a-real-secret';
$body=json_encode([
    'id'=>12345,'admin_graphql_api_id'=>'gid://shopify/Order/12345',
    'email'=>'must.not.persist@example.test',
    'shipping_address'=>['address1'=>'never write to Product inbox'],
],JSON_THROW_ON_ERROR);
$signature=base64_encode(hash_hmac('sha256',$body,$secret,true));
$gid=OrderPointer::extractVerified($body,$signature,$secret,'orders/paid');
if($gid!=='gid://shopify/Order/12345') throw new RuntimeException('Verified order pointer mismatch.');
if(OrderPointer::extractVerified($body,$signature,$secret,'products/update')!==null) {
    throw new RuntimeException('Non-order topic must not emit an order pointer.');
}
$reject=static function(callable $run):void {
    try {$run();}catch(Throwable) {return;}
    throw new RuntimeException('Invalid webhook was incorrectly accepted.');
};
$reject(static fn()=>OrderPointer::extractVerified($body,'fake',$secret,'orders/paid'));
$reject(static fn()=>OrderPointer::extractVerified(
    json_encode(['id'=>12345,'admin_graphql_api_id'=>'gid://shopify/Order/444'],JSON_THROW_ON_ERROR),
    base64_encode(hash_hmac('sha256',json_encode(['id'=>12345,'admin_graphql_api_id'=>'gid://shopify/Order/444'],JSON_THROW_ON_ERROR),$secret,true)),
    $secret,'orders/paid'));
$reject(static fn()=>OrderPointer::extractVerified(
    json_encode(['email'=>'no-id@example.test'],JSON_THROW_ON_ERROR),
    base64_encode(hash_hmac('sha256',json_encode(['email'=>'no-id@example.test'],JSON_THROW_ON_ERROR),$secret,true)),
    $secret,'orders/create'));
echo "SHOPIFY_S5_HMAC_ORDER_POINTER_OK\n";
