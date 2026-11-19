<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Domain\ProductRules;
use Throwable;

/**
 * Shopify-authoritative E4: stages read-only observations and checks approved mappings,
 * records exact external location/stock observations separately, never writes to Shopify.
 */
final readonly class ShopifyCatalogPullService
{
    private const PRODUCTS_QUERY = <<<'GRAPHQL'
query COSProducts($first: Int!, $after: String) {
  shop { currencyCode }
  products(first: $first, after: $after) {
    nodes {
      id title status updatedAt
      variants(first: 20) {
        nodes {
          id sku price barcode
          inventoryItem {
            id tracked
            inventoryLevels(first: 10) {
              nodes {
                location { id name }
                quantities(names: ["on_hand", "available"]) { name quantity }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        pageInfo { hasNextPage endCursor }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GRAPHQL;

    public function __construct(
        private ShopifyObservationStore $observations,
        private ShopifyReadClient $client,
        private ShopifyNestedConnectionReader $nested,
        private ShopifyClientCredentials $credentials,
        private ProductManagementService $products,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return array<string,mixed> */
    public function pull(string $org,string $connectionId,int $maxPages=1): array
    {
        if ($org===''||$connectionId===''||$maxPages<1||$maxPages>20) throw new DomainException('Invalid sync scope.');
        if (!$this->modules->isEnabled($org,'product_management')) {
            throw new DomainException('Product Management module is inactive for tenant.');
        }
        $connection=$this->observations->connection($org,$connectionId);
        if ($connection['mode']!=='shopify_authoritative'&&$connection['mode']!=='observation_only') {
            throw new DomainException('Connection mode not read-only.');
        }
        $token=$this->credentials->accessToken($connection['shop_domain'],$connection['secret_ref'],$org,$connectionId);
        $cursor=$connection['page_cursor'];
        $run=$this->observations->start($org,$connectionId);
        $pageCount=0;$productsSeen=0;$conflicts=0;
        try {
            do {
                $result=$this->client->query($connection['shop_domain'],$token,self::PRODUCTS_QUERY,
                    ['first'=>5,'after'=>$cursor]);
                $currency=$result['data']['shop']['currencyCode']??null;
                if (!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)) {
                    throw new DomainException('Shopify shop currency missing or invalid.');
                }
                $this->observations->recordShopCurrency($org,$connectionId,$currency);
                $page=$result['data']['products']??null;
                if (!is_array($page)||!is_array($page['nodes']??null)||!is_array($page['pageInfo']??null)) {
                    throw new DomainException('Unexpected products pagination shape.');
                }
                $seenOnPage=0;$conflictsOnPage=0;
                foreach ($page['nodes'] as $remote) {
                    ++$seenOnPage;
                    try {
                        // Retain raw provider evidence before expanding nested pages:
                        // partial/invalid pagination must not reach the COS stock ledger.
                        if (is_array($remote) && is_string($remote['id']??null)
                            && preg_match('#^gid://shopify/Product/[0-9]+$#D',$remote['id'])) {
                            $this->observations->observeProduct($org,$connectionId,$remote['id'],$remote);
                        }
                        if (!is_array($remote)) throw new DomainException('Malformed Shopify Product node.');
                        $complete=$this->nested->expand($connection['shop_domain'],$token,$remote);
                        $this->applyProduct($org,$connectionId,$complete);
                    }
                    catch (Throwable $e) {
                        ++$conflictsOnPage;
                        $gid=is_array($remote)?(string)($remote['id']??'unknown'):'unknown';
                        $this->observations->conflict($org,$connectionId,$gid,'product_import_blocked',
                            ['reason'=>mb_substr($e->getMessage(),0,300)]);
                    }
                }
                ++$pageCount;$productsSeen+=$seenOnPage;$conflicts+=$conflictsOnPage;
                $hasNext=$page['pageInfo']['hasNextPage']??null;
                $next=$page['pageInfo']['endCursor']??null;
                if (!is_bool($hasNext)||($hasNext&&(!is_string($next)||$next===''||strlen($next)>512||$next===$cursor))) {
                    throw new DomainException('Unsafe Shopify cursor.');
                }
                $cursor=$hasNext?$next:null;
                $this->observations->checkpoint($org,$connectionId,$run,$cursor??'', $seenOnPage,$conflictsOnPage,!$hasNext);
            } while ($hasNext && $pageCount<$maxPages);
            $this->observations->release($org,$connectionId,$run);
            return ['run_id'=>$run,'pages'=>$pageCount,'products_observed'=>$productsSeen,
                'conflicts'=>$conflicts,'status'=>$hasNext?'paused':'completed',
                'next_cursor_present'=>$hasNext];
        } catch (Throwable $e) {
            $this->observations->fail($org,$run,'shopify_read_or_persistence_failure');
            throw $e;
        }
    }

    /**
     * Observe remote facts without canonical Product/Variant mutations.
     * Unmapped identity must be reconciled under a separately approved Action.
     * @param array<string,mixed> $remote
     */
    private function applyProduct(string $org,string $conn,array $remote): void
    {
        $gid=$remote['id']??null;
        if (!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)) {
            throw new DomainException('Invalid Shopify product GID.');
        }
        // Retain an independently reviewable raw provider snapshot even when
        // downstream SKU, variants or stock shape is incomplete.
        $this->observations->observeProduct($org,$conn,$gid,$remote,true);
        $variants=$remote['variants']['nodes']??null;
        if (!is_array($variants)||$variants===[]||!empty($remote['variants']['pageInfo']['hasNextPage'])) {
            throw new DomainException('Empty or truncated Shopify variants; requires pagination/review.');
        }
        $normalized=[];
        foreach ($variants as $variant) {
            $variantGid=$variant['id']??null;
            if (!is_string($variantGid)||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$variantGid)) {
                throw new DomainException('Invalid Shopify variant GID.');
            }
            $sku=ProductRules::sku((string)($variant['sku']??''));
            if (!empty($variant['inventoryItem']['inventoryLevels']['pageInfo']['hasNextPage'])) {
                throw new DomainException('Inventory locations truncated; separate full pagination required.');
            }
            $normalized[]=['gid'=>$variantGid,'sku'=>$sku,'remote'=>$variant];
        }
        // A complete expanded snapshot is recorded only after all nested pages validate.
        foreach ($normalized as $variant) {
            $item=$variant['remote']['inventoryItem']??null;
            if (is_array($item) && is_string($item['id']??null)) {
                foreach ($item['inventoryLevels']['nodes']??[] as $level) {
                    if (is_array($level)) {
                        $this->observations->observeStock($org,$conn,$variant['gid'],$item['id'],$level);
                    }
                }
            }
        }
        $mapping=$this->observations->mapping($org,$conn,'product',$gid);
        if ($mapping===null) {
            throw new DomainException('Shopify product observed without approved COS identity mapping.');
        }
        $product=$this->products->getProduct($org,$mapping['canonical_id']);
        if ($product===null) throw new DomainException('Mapped canonical Product is absent.');
        foreach ($normalized as $variant) {
            $known=$this->observations->mapping($org,$conn,'variant',$variant['gid']);
            if ($known===null) throw new DomainException('Shopify variant observed without approved COS mapping.');
            $matched=false;
            foreach ($product['variants'] as $local) {
                if ($local['variant_id']===$known['canonical_id'] && $local['sku']===$variant['sku']) {
                    $matched=true; break;
                }
            }
            if (!$matched) throw new DomainException('Mapped Shopify Variant and canonical SKU disagree.');
        }
    }

}
