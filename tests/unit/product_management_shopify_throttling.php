<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface;

$http=new class implements ShopifyHttpTransportInterface {
    public int $calls=0;
    public bool $accessDenied=false;
    public function post(string $url,array $headers,string $body):array {
        if (!str_ends_with($url,'/admin/api/2026-10/graphql.json')
            ||($headers['X-Shopify-Access-Token']??null)!=='ci-token') {
            throw new RuntimeException('Shopify read transport unauthorized.');
        }
        ++$this->calls;
        if ($this->accessDenied) {
            return ['status'=>200,'body'=>json_encode(['errors'=>[
                ['extensions'=>['code'=>'ACCESS_DENIED']],
            ]],JSON_THROW_ON_ERROR)];
        }
        return match($this->calls) {
            1=>['status'=>429,'body'=>'{}'],
            2=>['status'=>200,'body'=>json_encode(['errors'=>[
                ['extensions'=>['code'=>'THROTTLED']],
            ]],JSON_THROW_ON_ERROR)],
            default=>['status'=>200,'body'=>'{"data":{"products":{"nodes":[]}}}'],
        };
    }
};
$reader=new ShopifyReadClient($http);
$reply=$reader->query('ci-store.myshopify.com','ci-token','query COSProducts { products(first:1) { nodes { id } } }');
if ($http->calls!==3 || !is_array($reply['data']['products']['nodes']??null)) {
    throw new RuntimeException('Shopify GraphQL throttle retry did not converge within budget.');
}
$http->accessDenied=true;$http->calls=0;
try {
    $reader->query('ci-store.myshopify.com','ci-token','query COSProducts { products(first:1) { nodes { id } } }');
    throw new RuntimeException('Shopify ACCESS_DENIED must reject without retries.');
} catch (DomainException) {
    if ($http->calls!==1) throw new RuntimeException('Non-throttle GraphQL error retried.');
}
try {
    $reader->query('ci-store.myshopify.com','ci-token','mutation Unsafe { productDelete(input:{}) { deletedProductId } }');
    throw new RuntimeException('Shopify mutating GraphQL was accepted.');
} catch (DomainException) {}
echo "Shopify read-only GraphQL bounded throttling, non-throttle and mutation guards passed.\n";
