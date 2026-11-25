<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Kernel\Module\ActiveModuleResolver;

/**
 * Tenant-bound, read-only Shopify Order evidence acquisition.
 *
 * This deliberately does not persist customer PII, mark an Order paid, touch
 * Finance or create a Sales record. It returns independently fetched provider
 * evidence for a separate, Sales-owned reconciliation contract.
 */
final readonly class ShopifyOrderReadbackService
{
    private const QUERY=<<<'GRAPHQL'
query COSOrderEvidence($id: ID!) {
  shop { currencyCode }
  order(id: $id) {
    id createdAt updatedAt
    displayFinancialStatus displayFulfillmentStatus
    currentTotalPriceSet { shopMoney { amount currencyCode } }
    lineItems(first: 100) {
      nodes { id sku quantity variant { id } }
      pageInfo { hasNextPage }
    }
  }
}
GRAPHQL;

    public function __construct(
        private ShopifyObservationStore $observations,
        private ShopifyClientCredentials $credentials,
        private ShopifyReadClient $client,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return array<string,mixed> */
    public function read(string $org,string $connectionId,string $orderGid):array
    {
        if($org===''||strlen($org)>40
            ||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org)
            ||!preg_match('/^[a-f0-9]{32}$/D',$connectionId)) {
            throw new DomainException('Shopify Order evidence requires bounded tenant and connection IDs.');
        }
        ShopifyOrderReadbackNormalizer::orderGid($orderGid);
        if(!$this->modules->isEnabled($org,'product_management')) {
            throw new DomainException('Product Management is inactive for tenant.');
        }
        $connection=$this->observations->connection($org,$connectionId);
        $shop=$connection['shop_domain']??null;
        if(!is_string($shop)) throw new DomainException('Shopify connection shop is missing.');
        ShopifyReadClient::assertShopDomain($shop);
        $token=$this->credentials->accessToken($shop,(string)$connection['secret_ref'],$org,$connectionId);
        try {
            $result=$this->client->query($shop,$token,self::QUERY,['id'=>$orderGid]);
        } finally {
            unset($token);
        }
        $evidence=ShopifyOrderReadbackNormalizer::normalize($result,$orderGid);
        return [
            'organization_id'=>$org,
            'connection_id'=>$connectionId,
            'shop_domain'=>$shop,
            ...$evidence,
        ];
    }
}
