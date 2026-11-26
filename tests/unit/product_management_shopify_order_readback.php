<?php
declare(strict_types=1);

require_once __DIR__.'/../../app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyOrderReadbackNormalizer.php';

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyOrderReadbackNormalizer as Normalize;

$gid='gid://shopify/Order/12345';
$source=[
    'data'=>[
        'shop'=>['currencyCode'=>'USD'],
        'order'=>[
            'id'=>$gid,'createdAt'=>'2026-11-23T10:00:00Z',
            'updatedAt'=>'2026-11-24T10:00:00Z',
            'displayFinancialStatus'=>'PAID',
            'displayFulfillmentStatus'=>'UNFULFILLED',
            'currentTotalPriceSet'=>['shopMoney'=>['amount'=>'990.00','currencyCode'=>'USD']],
            'email'=>'private@example.test',
            'shippingAddress'=>['address1'=>'Do not persist this address'],
            'lineItems'=>[
                'nodes'=>[[
                    'id'=>'gid://shopify/LineItem/222','sku'=>'SERIES01-F02','quantity'=>1,
                    'variant'=>['id'=>'gid://shopify/ProductVariant/333'],
                ]],
                'pageInfo'=>['hasNextPage'=>false],
            ],
        ],
    ],
];
$e=Normalize::normalize($source,$gid);
if($e['financial_status']!=='PAID'
    ||$e['current_total_shop_amount']!=='990.00'
    ||$e['line_items'][0]['sku']!=='SERIES01-F02'
    ||$e['payment_settled']!==false
    ||$e['marketing_attribution_verified']!==false) {
    throw new RuntimeException('Order evidence normalization mismatch.');
}
$json=json_encode($e,JSON_THROW_ON_ERROR);
foreach(['private@example.test','Do not persist this address','shippingAddress','email'] as $pii) {
    if(str_contains($json,$pii)) throw new RuntimeException('Sensitive source fields leaked to evidence.');
}
$again=Normalize::normalize($source,$gid);
if($again['snapshot_sha256']!==$e['snapshot_sha256']) {
    throw new RuntimeException('Order evidence digest must be deterministic.');
}
$reject=static function(array $payload,string $expected) {
    try {Normalize::normalize($payload,$expected);} catch(DomainException) {return;}
    throw new RuntimeException('Invalid Shopify Order evidence not rejected.');
};
$changed=$source;$changed['data']['order']['id']='gid://shopify/Order/2222';
$reject($changed,$gid);
$changed=$source;$changed['data']['shop']['currencyCode']='EUR';
$reject($changed,$gid);
$changed=$source;$changed['data']['order']['lineItems']['pageInfo']['hasNextPage']=true;
$reject($changed,$gid);
$changed=$source;$changed['data']['order']['lineItems']['nodes'][0]['quantity']=-1;
$reject($changed,$gid);
$changed=$source;$changed['data']['order']['lineItems']['nodes'][0]['variant']['id']='gid://shopify/Product/9';
$reject($changed,$gid);
$changed=$source;$changed['data']['order']['createdAt']='not-a-date';
$reject($changed,$gid);
echo "SHOPIFY_ORDER_READBACK_NON_PII_CONTRACT_OK\n";

require_once __DIR__.'/../../app/Domains/ProductManagement/Infrastructure/Shopify/ShopifySandboxOrderAcceptance.php';
$connection=str_repeat('a',32);
$testOrder=['channel'=>'shopify','store_reference'=>$connection,'external_order_id'=>$gid,
    'test'=>true,'blocked_reason'=>'test_order','terms'=>null,'provider_hash'=>str_repeat('b',64),'email'=>'not-exported@example.test'];
$sandbox=\Domains\ProductManagement\Infrastructure\Shopify\ShopifySandboxOrderAcceptance::verify($testOrder,$connection,$gid);
if($sandbox['status']!=='SANDBOX_READBACK_VERIFIED'||$sandbox['native_order_created']||$sandbox['local_stock_mutation']||
    $sandbox['payment_settled']||$sandbox['provider_mutation']||str_contains(json_encode($sandbox,JSON_THROW_ON_ERROR),'not-exported'))
    throw new RuntimeException('Sandbox isolation evidence invalid.');
foreach([['test'=>false],['terms'=>[]],['blocked_reason'=>null],['store_reference'=>str_repeat('c',32)],['provider_hash'=>'invalid']] as $patch){
    try{\Domains\ProductManagement\Infrastructure\Shopify\ShopifySandboxOrderAcceptance::verify([...$testOrder,...$patch],$connection,$gid);}
    catch(DomainException){continue;}
    throw new RuntimeException('Unsafe sandbox evidence accepted.');
}
echo "SHOPIFY_SANDBOX_TEST_ORDER_ISOLATION_CONTRACT_OK\n";

$org='sandbox-fixture';
$line='gid://shopify/LineItem/222';
$now='2026-11-24T10:00:00.000000Z';
$testOrder=[...$testOrder,'updated_at'=>$now,'cancelled_at'=>$now,'fulfillment_status'=>'PARTIALLY_FULFILLED','line_identities'=>[$line=>3]];
$lifecycle=['organization_id'=>$org,'connection_id'=>$connection,'channel'=>'shopify','external_order_id'=>$gid,'test'=>true,
    'provider_hash'=>str_repeat('c',64),'provider_updated_at'=>$now,'cancelled_at'=>$now,'provider_fulfillment_status'=>'PARTIALLY_FULFILLED',
    'inventory_authority'=>'external_review','local_stock_write_allowed'=>false,'physical_commitment_verified'=>false,
    'lifecycle'=>['lines'=>[$line=>['ordered'=>3,'assigned_pending'=>0,'provider_fulfilled'=>2,'return_requested'=>1,'return_processed'=>1,'cancelled_unfulfilled'=>1]],'review_reasons'=>[]]];
$proof=\Domains\ProductManagement\Infrastructure\Shopify\ShopifySandboxOrderAcceptance::verifyLifecycle($testOrder,$lifecycle,$org,$connection,$gid);
if($proof['status']!=='SANDBOX_LIFECYCLE_READBACK_VERIFIED'||$proof['provider_quantities']!==[
    'ordered'=>3,'assigned_pending'=>0,'provider_fulfilled'=>2,'return_requested'=>1,'return_processed'=>1,'cancelled_unfulfilled'=>1]
    ||$proof['ownership_changed']||$proof['physical_commitment_verified']||$proof['native_order_created']||$proof['payment_settled']
    ||$proof['scenario_acceptance']!=='independent_review_required')throw new RuntimeException('Sandbox provider lifecycle readback invalid.');
foreach([['test'=>false],['organization_id'=>'foreign'],['provider_updated_at'=>'2026-11-25T10:00:00.000000Z'],
    ['provider_fulfillment_status'=>'FULFILLED'],['local_stock_write_allowed'=>true],['physical_commitment_verified'=>true],
    ['lifecycle'=>['lines'=>['gid://shopify/LineItem/223'=>$lifecycle['lifecycle']['lines'][$line]],'review_reasons'=>[]]]] as $patch){
    try{\Domains\ProductManagement\Infrastructure\Shopify\ShopifySandboxOrderAcceptance::verifyLifecycle($testOrder,[...$lifecycle,...$patch],$org,$connection,$gid);}
    catch(DomainException){continue;}
    throw new RuntimeException('Unsafe sandbox lifecycle accepted.');
}
echo "SHOPIFY_SANDBOX_LIFECYCLE_INDEPENDENT_READBACK_CONTRACT_OK\n";
