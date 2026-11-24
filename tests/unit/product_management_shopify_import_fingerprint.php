<?php
declare(strict_types=1);
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php');
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportCandidateNormalizer;
$first=['gid'=>'gid://shopify/Product/1','name'=>'Test','variants'=>[
    ['price'=>'10.00','sku'=>'A','barcode'=>null],
    ['sku'=>'B','price'=>'20.00']]];
$other=['variants'=>[
    ['barcode'=>null,'sku'=>'A','price'=>'10.00'],
    ['price'=>'20.00','sku'=>'B']],
    'name'=>'Test','gid'=>'gid://shopify/Product/1'];
if(ShopifyImportCandidateNormalizer::fingerprint($first)
  !==ShopifyImportCandidateNormalizer::fingerprint($other)) {
    throw new RuntimeException('Canonical Shopify fingerprint depends on JSON object key order.');
}
$other['variants'][0]['price']='10.01';
if(ShopifyImportCandidateNormalizer::fingerprint($first)
  ===ShopifyImportCandidateNormalizer::fingerprint($other)) {
    throw new RuntimeException('Shopify fingerprint did not detect price drift.');
}
echo "Shopify immutable candidate canonical hash passed.\n";
