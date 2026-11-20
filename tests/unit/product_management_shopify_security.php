<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyWebhookVerifier;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface;

$http=new class implements ShopifyHttpTransportInterface {
    public int $calls=0;
    public function post(string $url,array $headers,string $body):array {
        ++$this->calls;
        return ['status'=>200,'body'=>'{"data":{"shop":{"name":"Pilot"}}}'];
    }
};
$api=new ShopifyReadClient($http);
$data=$api->query('demo-shop.myshopify.com','test-token','query Pilot { shop { name } }');
if ($data['data']['shop']['name']!=='Pilot'||$http->calls!==1) throw new RuntimeException('GraphQL read failed.');
try {
    $api->query('demo-shop.myshopify.com','test-token','mutation Bad { productDelete(input:{id:"x"}) { deletedProductId } }');
    throw new RuntimeException('Mutation guard bypassed.');
} catch (DomainException) {}
if ($http->calls!==1) throw new RuntimeException('Write request reached transport.');
try { $api->query('127.0.0.1','x','query X { shop { id } }'); throw new RuntimeException('SSRF guard bypassed.'); }
catch (DomainException) {}
$payload='{"id":1}';$secret='local-test-key';
$hmac=base64_encode(hash_hmac('sha256',$payload,$secret,true));
ShopifyWebhookVerifier::verify($payload,$hmac,$secret);
try { ShopifyWebhookVerifier::verify($payload.'x',$hmac,$secret); throw new RuntimeException('Webhook accepted invalid HMAC.'); }
catch (DomainException) {}
echo "Product Management Shopify security passed.\n";
