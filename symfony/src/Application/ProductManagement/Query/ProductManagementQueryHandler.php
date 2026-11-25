<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Query;

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\ProductMediaService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductAnalytics;
use Kernel\Application\Query\QueryHandlerInterface;
use DomainException;

final readonly class ProductManagementQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductManagementService $products,
        private PricingService $prices,
        private CsvProductImportService $imports,
        private ShopifyObservationStore $shopify,
        private ProductMediaService $media,
        private MySqlProductAnalytics $analytics,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(ProductManagementQuery $query): array
    {
        $org=$query->organization->value();
        $in=$query->input;
        return match($query->operation) {
            'analytics.summary.get' => $this->analytics->summary($org,(string)$in['currency']),
            'analytics.stock.search' => $this->analytics->stock($org,isset($in['location_id'])?(string)$in['location_id']:null,(string)($in['risk']??'all'),(string)($in['low']??'5'),(string)($in['high']??'100'),(int)($in['limit']??30),(int)($in['offset']??0)),
            'analytics.movement.search' => $this->analytics->movement($org,(string)$in['from'],(string)$in['to'],(int)($in['limit']??30),(int)($in['offset']??0)),
            'analytics.quality.search' => $this->analytics->quality($org,(string)$in['currency'],(string)$in['kind'],(int)($in['limit']??30),(int)($in['offset']??0)),
            'analytics.reconciliation.get' => $this->analytics->reconciliation($org,(int)($in['limit']??100)),
            'attribute.search' => ['items'=>$this->products->attributes($org,(int)($in['limit']??30),(int)($in['offset']??0)),'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0)],
            'attribute.category.get' => ['items'=>$this->products->categoryAttributes($org,(string)$in['category_id'])],
            'attribute.product.get' => $this->products->productAttributes($org,(string)$in['product_id']),
            'attribute.product.filter' => ['items'=>$this->products->filterAttributes($org,(string)$in['code'],$in['value'],(int)($in['limit']??30),(int)($in['offset']??0)),'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0)],
            'variant.matrix.preview' => $this->products->variantMatrix($org,(string)$in['product_id'],$in['axes'],(string)$in['sku_prefix']),
            'media.search' => [
                'items'=>$this->media->search($org,(string)$in['product_id'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'category.search' => [
                'items'=>$this->products->categories($org,(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'location.search' => [
                'items'=>$this->products->locations($org,(string)($in['q']??''),(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'product.get' => $this->get($org,(string)$in['product_id']),
            'product.search' => [
                'items'=>$this->products->searchProducts($org,(string)($in['q']??''),(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'stock.movement.search' => [
                'items'=>$this->products->stockMovements($org,(string)$in['variant_id'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'stock.reservation.search' => [
                'items'=>$this->products->reservations($org,(string)$in['variant_id'],(string)$in['location_id'],isset($in['reservation_id'])?(string)$in['reservation_id']:null,(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'stock.count.session.candidates' => [
                'items'=>$this->products->countCandidates($org,(string)$in['location_id'],(int)($in['limit']??50),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??50),'offset'=>(int)($in['offset']??0),
            ],
            'stock.count.session.get' => ['session'=>$this->products->countSession($org,(string)$in['session_id'])],
            'stock.count.get' => ['count'=>$this->products->stockCount($org,(string)$in['count_id'])],
            'stock.balance.get' => $this->products->balance($org,(string)$in['variant_id'],(string)$in['location_id']),
            'import.batch.get' => $this->imports->report($org,(string)$in['batch_id']),
            'price.list.history.search' => [
                'items'=>$this->prices->listHistory($org,(string)$in['list_id'],(string)$in['variant_id'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'price.list.tier.history.search' => [
                'items'=>$this->prices->tierHistory($org,(string)$in['list_id'],(string)$in['variant_id'],(string)$in['min_quantity'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'price.list.tier.search' => [
                'items'=>$this->prices->tiers($org,(string)$in['list_id'],(string)$in['variant_id'],(int)($in['limit']??30),(int)($in['offset']??0),isset($in['min_quantity'])?(string)$in['min_quantity']:null),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'price.resolve' => $this->prices->resolvePrice($org,(string)$in['variant_id'],(string)$in['currency'],(string)$in['quantity'],isset($in['list_id'])?(string)$in['list_id']:null),
            'price.list.search' => [
                'items'=>$this->prices->searchLists($org,isset($in['code'])?(string)$in['code']:null,(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'price.list.entry.get' => $this->prices->getListPrice($org,(string)$in['list_id'],(string)$in['variant_id']),
            'price.history.search' => [
                'items'=>$this->prices->history($org,(string)$in['variant_id'],(string)$in['currency'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'price.base.get' => $this->prices->get($org,(string)$in['variant_id'],(string)$in['currency']),
            'sync.status.get' => $this->shopify->status($org,(string)$in['connection_id']),
            default => throw new DomainException('Unknown Product Management query.'),
        };
    }

    /** @return array<string,mixed> */
    private function get(string $org,string $id): array
    {
        $product=$this->products->getProduct($org,$id);
        return $product===null?['found'=>false]:['found'=>true,'product'=>$product];
    }
}
