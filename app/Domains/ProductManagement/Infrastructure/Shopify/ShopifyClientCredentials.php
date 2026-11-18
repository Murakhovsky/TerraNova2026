<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/**
 * Pilot-only credential resolver. Secrets are sourced from server environment,
 * never from manifests, database payloads, UI, or logs.
 */
final readonly class ShopifyClientCredentials
{
    public function __construct(private ShopifyHttpTransportInterface $http) {}

    public function accessToken(string $shopDomain,string $secretRef): string
    {
        ShopifyReadClient::assertShopDomain($shopDomain);
        if ($secretRef!=='shopify_env_primary') throw new DomainException('Unrecognized Shopify secret reference.');
        $configured=getenv('SHOPIFY_SHOP_DOMAIN');
        $id=getenv('SHOPIFY_CLIENT_ID');
        $secret=getenv('SHOPIFY_CLIENT_SECRET');
        if (!is_string($configured)||$configured!==$shopDomain || !is_string($id)||$id==='' || !is_string($secret)||$secret==='') {
            throw new DomainException('Shopify server credentials not configured for this connection.');
        }
        $result=$this->http->post('https://'.$shopDomain.'/admin/oauth/access_token',[
            'Content-Type'=>'application/x-www-form-urlencoded',
        ],http_build_query(['grant_type'=>'client_credentials','client_id'=>$id,'client_secret'=>$secret],'','&',PHP_QUERY_RFC3986));
        if ($result['status']!==200) throw new DomainException('Shopify client credentials rejected.');
        $payload=json_decode($result['body'],true,flags:JSON_THROW_ON_ERROR);
        $token=$payload['access_token']??null;
        if (!is_string($token)||$token==='') throw new DomainException('Shopify OAuth token missing.');
        return $token;
    }
}
