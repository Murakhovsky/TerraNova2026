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
