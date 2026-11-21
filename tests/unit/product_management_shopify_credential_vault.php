<?php
declare(strict_types=1);
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyClientCredentials;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

$vault = new class implements CredentialVaultInterface {
    public array $contexts = [];
    public function resolve(Credential $credential): array {
        $this->contexts[] = [
            $credential->organizationId->value(),$credential->connectionId,
            $credential->secretReference,
        ];
        return match ($credential->secretReference) {
            'env://COS_SHOPIFY_STORE_A' => [
                'shop_domain'=>'store-a.myshopify.com',
                'client_id'=>'client-a',
                'client_secret'=>'secret-a',
            ],
            'env://COS_SHOPIFY_STORE_B' => [
                'shop_domain'=>'store-b.myshopify.com',
                'client_id'=>'client-b',
                'client_secret'=>'secret-b',
            ],
            default => throw new DomainException('Unknown secret'),
        };
    }
};
$http = new class implements ShopifyHttpTransportInterface {
    public array $requests = [];
    public function post(string $url,array $headers,string $body):array {
        $this->requests[]=['url'=>$url,'body'=>$body];
        return ['status'=>200,'body'=>'{"access_token":"read-only-test-token"}'];
    }
};
$credentials=new ShopifyClientCredentials($http,$vault);
$check=static function(bool $ok,string $reason):void {
    if (!$ok) throw new RuntimeException($reason);
};
$token=$credentials->accessToken('store-a.myshopify.com','env://COS_SHOPIFY_STORE_A','tenant-a','conn-a');
$check($token==='read-only-test-token' && count($http->requests)===1,'Store A OAuth unavailable.');
$check(str_contains($http->requests[0]['body'],'client_id=client-a')
    && str_contains($http->requests[0]['body'],'client_secret=secret-a'),
    'Store A OAuth was not scoped to its Vault credentials.');
$check($credentials->webhookSecret('store-b.myshopify.com','env://COS_SHOPIFY_STORE_B','tenant-b','conn-b')
    ==='secret-b','Store B webhook HMAC did not resolve its own secret.');
$check($vault->contexts[0]===['tenant-a','conn-a','env://COS_SHOPIFY_STORE_A']
    && $vault->contexts[1]===['tenant-b','conn-b','env://COS_SHOPIFY_STORE_B'],
    'Vault lost tenant/connection scope.');
$before=count($http->requests);
foreach ([
    ['store-a.myshopify.com','env://COS_SHOPIFY_STORE_B','tenant-a','conn-a'],
    ['store-a.myshopify.com','env://COS_SHOPIFY_STORE_A',null,null],
    ['store-a.myshopify.com','env://OTHER_SECRET','tenant-a','conn-a'],
] as [$shop,$ref,$tenant,$conn]) {
    try {
        $credentials->accessToken($shop,$ref,$tenant,$conn);
        throw new RuntimeException('Credential safety boundary bypassed.');
    } catch (DomainException $expected) {
        if ($expected->getMessage()==='Credential safety boundary bypassed.') throw $expected;
    }
}
$check(count($http->requests)===$before,'Rejected credentials reached OAuth transport.');
echo "Product Management multi-store Credential Vault boundary passed.\n";
