<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/**
 * Completes nested Shopify cursor connections before any stock observations.
 * Every failure is fail-closed: caller retains original snapshot and quarantines
 * the Product instead of persisting partial location quantities.
 */
final readonly class ShopifyNestedConnectionReader
{
    private const MAX_VARIANT_PAGES = 128;
    private const MAX_LOCATION_PAGES = 40;
    private const MAX_ADDITIONAL_CALLS = 256;

    private const VARIANT_QUERY = <<<'GRAPHQL'
query COSProductVariants($id: ID!, $first: Int!, $after: String) {
  product(id: $id) {
    id
    variants(first: $first, after: $after) {
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
}
GRAPHQL;

    private const LEVEL_QUERY = <<<'GRAPHQL'
query COSInventoryLevels($id: ID!, $first: Int!, $after: String) {
  inventoryItem(id: $id) {
    id
    inventoryLevels(first: $first, after: $after) {
      nodes {
        location { id name }
        quantities(names: ["on_hand", "available"]) { name quantity }
      }
      pageInfo { hasNextPage endCursor }
    }
  }
}
GRAPHQL;

    public function __construct(private ShopifyReadClient $client) {}

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function expand(string $shop,string $token,array $product): array
    {
        $gid=$product['id']??null;
        if (!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)) {
            throw new DomainException('Invalid Shopify Product ID in nested sync.');
        }
        $connection=$product['variants']??null;
        $nodes=$this->nodes($connection,20,'variants');
        $pages=1;$calls=0;$seenCursors=[];
        while (true) {
            $cursor=$this->nextCursor($connection,$seenCursors,'variants');
            if ($cursor===null) break;
            if ($pages>=self::MAX_VARIANT_PAGES) {
                throw new DomainException('Shopify variants exceed bounded pagination budget.');
            }
            $this->budget($calls);
            $response=$this->client->query($shop,$token,self::VARIANT_QUERY,
                ['id'=>$gid,'first'=>20,'after'=>$cursor]);
            $next=$response['data']['product']??null;
            if (!is_array($next)||($next['id']??null)!==$gid) {
                throw new DomainException('Shopify Product cursor changed identity.');
            }
            $connection=$next['variants']??null;
            array_push($nodes,...$this->nodes($connection,20,'variants'));
            ++$pages;
        }

        $seenVariants=[];
        foreach ($nodes as &$variant) {
            $variantId=$variant['id']??null;
            if (!is_string($variantId)||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$variantId)
                ||isset($seenVariants[$variantId])) {
                throw new DomainException('Missing or duplicate Shopify Variant ID.');
            }
            $seenVariants[$variantId]=true;
            $item=$variant['inventoryItem']??null;
            if ($item===null) continue;
            $itemId=$item['id']??null;
            if (!is_string($itemId)||!preg_match('#^gid://shopify/InventoryItem/[0-9]+$#D',$itemId)) {
                throw new DomainException('Invalid Shopify InventoryItem ID.');
            }
            $levels=$item['inventoryLevels']??null;
            $locations=$this->nodes($levels,10,'inventoryLevels');
            $levelPages=1;$levelCursors=[];
            while (true) {
                $cursor=$this->nextCursor($levels,$levelCursors,'inventoryLevels');
                if ($cursor===null) break;
                if ($levelPages>=self::MAX_LOCATION_PAGES) {
                    throw new DomainException('Shopify inventory locations exceed bounded pagination budget.');
                }
                $this->budget($calls);
                $response=$this->client->query($shop,$token,self::LEVEL_QUERY,
                    ['id'=>$itemId,'first'=>10,'after'=>$cursor]);
                $nextItem=$response['data']['inventoryItem']??null;
                if (!is_array($nextItem)||($nextItem['id']??null)!==$itemId) {
                    throw new DomainException('Shopify InventoryItem cursor changed identity.');
                }
                $levels=$nextItem['inventoryLevels']??null;
                array_push($locations,...$this->nodes($levels,10,'inventoryLevels'));
                ++$levelPages;
            }
            $seenLocations=[];
            foreach ($locations as $level) {
                $locationId=$level['location']['id']??null;
                if (!is_string($locationId)
                    ||!preg_match('#^gid://shopify/Location/[0-9]+$#D',$locationId)
                    ||isset($seenLocations[$locationId])) {
                    throw new DomainException('Missing or duplicate Shopify inventory location.');
                }
                $seenLocations[$locationId]=true;
            }
            $variant['inventoryItem']['inventoryLevels']=[
                'nodes'=>$locations,'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
            ];
        }
        unset($variant);
        $product['variants']=['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null]];
        return $product;
    }

    /** @param mixed $connection @return list<array<string,mixed>> */
    private function nodes(mixed $connection,int $maximum,string $label): array
    {
        if (!is_array($connection)||!is_array($connection['nodes']??null)
            ||!array_is_list($connection['nodes'])||count($connection['nodes'])>$maximum
            ||!is_array($connection['pageInfo']??null)
            ||!is_bool($connection['pageInfo']['hasNextPage']??null)) {
            throw new DomainException('Malformed or oversized Shopify '.$label.' connection.');
        }
        foreach ($connection['nodes'] as $node) {
            if (!is_array($node)) throw new DomainException('Malformed Shopify '.$label.' node.');
        }
        return $connection['nodes'];
    }

    /** @param mixed $connection @param array<string,bool> $seen */
    private function nextCursor(mixed $connection,array &$seen,string $label): ?string
    {
        if (!is_array($connection)||!is_array($connection['pageInfo']??null)
            ||!is_bool($connection['pageInfo']['hasNextPage']??null)) {
            throw new DomainException('Malformed Shopify '.$label.' pageInfo.');
        }
        if ($connection['pageInfo']['hasNextPage']===false) return null;
        $cursor=$connection['pageInfo']['endCursor']??null;
        if (!is_string($cursor)||$cursor===''||strlen($cursor)>512||isset($seen[$cursor])) {
            throw new DomainException('Missing, repeated or oversized Shopify '.$label.' cursor.');
        }
        $seen[$cursor]=true;
        return $cursor;
    }

    private function budget(int &$calls): void
    {
        if (++$calls>self::MAX_ADDITIONAL_CALLS) {
            throw new DomainException('Shopify nested pagination request budget exceeded.');
        }
    }
}
