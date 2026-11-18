<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Domain\ProductRules;
use Throwable;

/**
 * Shopify-authoritative E4: imports canonical identity where unambiguous,
 * records exact external location/stock observations separately, never writes to Shopify.
 */
final readonly class ShopifyCatalogPullService
{
    private const PRODUCTS_QUERY = <<<'GRAPHQL'
query COSProducts($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    nodes {
      id title status updatedAt
      variants(first: 100) {
        nodes {
          id sku price barcode
          inventoryItem {
            id tracked
            inventoryLevels(first: 50) {
              nodes {
                location { id name }
                quantities(names: ["on_hand", "available"]) { name quantity }
              }
              pageInfo { hasNextPage }
            }
          }
        }
        pageInfo { hasNextPage }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GRAPHQL;

    public function __construct(
        private ShopifyObservationStore $observations,
        private ShopifyReadClient $client,
        private ShopifyClientCredentials $credentials,
        private ProductManagementService $products,
    ) {}

    /** @return array<string,mixed> */
    public function pull(string $org,string $connectionId,int $maxPages=1): array
    {
        if ($org===''||$connectionId===''||$maxPages<1||$maxPages>20) throw new DomainException('Invalid sync scope.');
        $connection=$this->observations->connection($org,$connectionId);
        if ($connection['mode']!=='shopify_authoritative'&&$connection['mode']!=='observation_only') {
            throw new DomainException('Connection mode not read-only.');
        }
        $token=$this->credentials->accessToken($connection['shop_domain'],$connection['secret_ref']);
        $cursor=$connection['cursor'];
        $run=$this->observations->start($org,$connectionId);
        $pageCount=0;$productsSeen=0;$conflicts=0;
        try {
            do {
                $result=$this->client->query($connection['shop_domain'],$token,self::PRODUCTS_QUERY,
                    ['first'=>25,'after'=>$cursor]);
                $page=$result['data']['products']??null;
                if (!is_array($page)||!is_array($page['nodes']??null)||!is_array($page['pageInfo']??null)) {
                    throw new DomainException('Unexpected products pagination shape.');
                }
                $seenOnPage=0;$conflictsOnPage=0;
                foreach ($page['nodes'] as $remote) {
                    ++$seenOnPage;
                    try { $this->applyProduct($org,$connectionId,$remote); }
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
                if (!is_bool($hasNext)||($hasNext&&(!is_string($next)||$next===''))) {
                    throw new DomainException('Unsafe Shopify cursor.');
                }
                $cursor=$hasNext?$next:null;
                $this->observations->checkpoint($org,$connectionId,$run,$cursor??'', $seenOnPage,$conflictsOnPage,!$hasNext);
            } while ($hasNext && $pageCount<$maxPages);
            return ['run_id'=>$run,'pages'=>$pageCount,'products_observed'=>$productsSeen,
                'conflicts'=>$conflicts,'status'=>$hasNext?'paused':'completed',
                'next_cursor_present'=>$hasNext];
        } catch (Throwable $e) {
            $this->observations->fail($org,$run,'shopify_read_or_persistence_failure');
            throw $e;
        }
    }

    /** @param array<string,mixed> $remote */
    private function applyProduct(string $org,string $conn,array $remote): void
    {
        $gid=$remote['id']??null;
        if (!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)) throw new DomainException('Invalid Shopify product GID.');
        $variants=$remote['variants']['nodes']??null;
        if (!is_array($variants)||$variants===[]||!empty($remote['variants']['pageInfo']['hasNextPage'])) {
            throw new DomainException('Empty or unbounded variant connection, stage for bulk review.');
        }
        $normalized=[];
        foreach ($variants as $variant) {
            $vgid=$variant['id']??null;
            if (!is_string($vgid)||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$vgid)) throw new DomainException('Invalid Shopify variant GID.');
            $sku=ProductRules::sku((string)($variant['sku']??''));
            $normalized[]=['sku'=>$sku,'gid'=>$vgid,'remote'=>$variant];
            if (!empty($variant['inventoryItem']['inventoryLevels']['pageInfo']['hasNextPage'])) {
                throw new DomainException('Inventory locations truncated; require full pagination.');
            }
        }
        $first=$normalized[0];
        $mapping=$this->observations->mapping($org,$conn,'product',$gid);
        if ($mapping===null) {
            $tracked=(bool)($first['remote']['inventoryItem']['tracked']??false);
            $data=['name'=>ProductRules::name((string)($remote['title']??'')),
                'sku'=>$first['sku'],'type'=>$tracked?'stock':'service',
                'uom'=>'pcs','track_inventory'=>$tracked];
            $created=$this->products->createProduct($org,$data,$this->idem($conn,'p',$gid),
                'shopify:'.$conn,$this->idem($conn,'trace',$gid));
            $mapping=['canonical_id'=>$created['product_id']];
            $this->observations->map($org,$conn,'product',$gid,$created['product_id']);
            $this->observations->map($org,$conn,'variant',$first['gid'],$created['variant_id']);
        }
        $product=$this->products->getProduct($org,$mapping['canonical_id']);
        if ($product===null) throw new DomainException('Mapped canonical product is missing.');
        foreach ($normalized as $variant) {
            $known=$this->observations->mapping($org,$conn,'variant',$variant['gid']);
            if ($known===null) {
                $local=null;
                foreach ($product['variants'] as $v) {
                    if ($v['sku']===$variant['sku']) {$local=$v['variant_id'];break;}
                }
                if ($local===null) {
                    $remoteTracked=(bool)($variant['remote']['inventoryItem']['tracked']??false);
                    $created=$this->products->createVariant($org,$product['product_id'],$variant['sku'],'pcs',$remoteTracked,
                        $this->idem($conn,'v',$variant['gid']),'shopify:'.$conn,$this->idem($conn,'trace',$variant['gid']));
                    $local=$created['variant_id'];
                }
                $this->observations->map($org,$conn,'variant',$variant['gid'],$local);
            }
            $item=$variant['remote']['inventoryItem']??null;
            if (is_array($item) && is_string($item['id']??null)) {
                foreach ($item['inventoryLevels']['nodes']??[] as $level) {
                    if (is_array($level)) $this->observations->observeStock($org,$conn,$variant['gid'],$item['id'],$level);
                }
            }
        }
        $this->observations->observeProduct($org,$conn,$gid,$remote);
    }

    private function idem(string $connectionId,string $kind,string $gid): string
    {
        return 'pm:'.hash('sha256',$connectionId.':'.$kind.':'.$gid);
    }
}
