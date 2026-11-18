<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/** Admin GraphQL read queries only. No mutation operation is accepted. */
final readonly class ShopifyReadClient
{
    public const API_VERSION = '2026-10';

    public function __construct(private ShopifyHttpTransportInterface $http) {}

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function query(string $shopDomain, string $token, string $query, array $variables=[]): array
    {
        self::assertShopDomain($shopDomain);
        if ($token===''||strlen($token)>2048) throw new DomainException('Missing Shopify access token.');
        if (!preg_match('/^\s*query\b/i',$query)
            || preg_match('/\bmutation\b/i',$query)) throw new DomainException('Shopify write operation is forbidden in E4.');
        $response=$this->http->post('https://'.$shopDomain.'/admin/api/'.self::API_VERSION.'/graphql.json',[
            'Content-Type'=>'application/json',
            'X-Shopify-Access-Token'=>$token,
        ],json_encode(['query'=>$query,'variables'=>$variables],JSON_THROW_ON_ERROR));
        if ($response['status']<200||$response['status']>=300) throw new DomainException('Shopify HTTP read error: '.$response['status']);
        $result=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($result)||!isset($result['data'])||!is_array($result['data']) || !empty($result['errors'])) {
            throw new DomainException('Shopify GraphQL errors or invalid response.');
        }
        return $result;
    }

    public static function assertShopDomain(string $host): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D',$host)||strlen($host)>190) {
            throw new DomainException('Shop domain must be <shop>.myshopify.com.');
        }
    }
}
