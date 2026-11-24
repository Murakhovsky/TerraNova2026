<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/Domains/ProductManagement/Domain/ProductRules.php';
require_once __DIR__.'/../../app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyOutboundPlanFactory.php';

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyOutboundPlanFactory as Planner;

$org='tenant-series-01';$conn=str_repeat('b',32);$id=str_repeat('a',32);
$native=['product_id'=>$id,'name'=>'ARC Desk Lamp','description'=>'AI concept: not yet manufactured.',
    'variants'=>[['sku'=>'SERIES01-L01']]];
$draft=Planner::draftProduct($org,$conn,$native,'390.00');
$vars=$draft['variables'];
if($vars['input']['status']!=='DRAFT'||$draft['publishable']!==false
    ||$draft['requires_approved_action']!==true
    ||$vars['input']['variants'][0]['sku']!=='SERIES01-L01'
    ||$vars['identifier']['handle']!==$vars['input']['handle']
    ||!str_starts_with($vars['input']['descriptionHtml'],'<p>AI concept')) {
    throw new RuntimeException('Shopify S3 draft invariant failed.');
}
$changed=Planner::draftProduct('tenant-other',$conn,$native,'390.00');
if($changed['variables']['identifier']===$vars['identifier']) {
    throw new RuntimeException('Shopify deterministic handle must be tenant-specific.');
}
$publication=Planner::publicationRequest($org,$conn,'gid://shopify/Product/123','gid://shopify/Publication/456');
if(!str_contains($publication['query'],'publishablePublish')
    ||$publication['blocked_until']===[]) {
    throw new RuntimeException('Shopify S4 publication must remain approval gated.');
}
$readback=['data'=>['product'=>[
    'id'=>'gid://shopify/Product/123','handle'=>$vars['input']['handle'],'status'=>'DRAFT',
    'variants'=>['nodes'=>[['id'=>'gid://shopify/ProductVariant/333',
        'sku'=>'SERIES01-L01','price'=>'390.00']]]
]]];
if(Planner::verifyDraftReadback($readback,$vars['input']['handle'],'SERIES01-L01','390.00')
    !=='gid://shopify/Product/123') {
    throw new RuntimeException('Shopify S3 verified readback failed.');
}
$reject=static function(callable $run):void {
    try {$run();} catch(DomainException) {return;}
    throw new RuntimeException('Invalid or unsafe Shopify operation was not rejected.');
};
$reject(static fn()=>Planner::draftProduct($org,$conn,$native,'299.00'));
$reject(static fn()=>Planner::draftProduct($org,$conn,
    [...$native,'variants'=>[['sku'=>'SERIES01-L01'],['sku'=>'SERIES01-L02']]],'390.00'));
$reject(static fn()=>Planner::publicationRequest($org,$conn,'other-id','gid://shopify/Publication/456'));
$reject(static fn()=>Planner::verifyDraftReadback(
    ['data'=>['product'=>[...$readback['data']['product'],'status'=>'ACTIVE']]],
    $vars['input']['handle'],'SERIES01-L01','390.00'));
echo "SHOPIFY_S3_S4_OUTBOUND_DRAFT_CONTRACT_OK\n";
