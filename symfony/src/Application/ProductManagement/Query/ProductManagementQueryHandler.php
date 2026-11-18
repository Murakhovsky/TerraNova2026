<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Query;

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Kernel\Application\Query\QueryHandlerInterface;
use DomainException;

final readonly class ProductManagementQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductManagementService $products,
        private PricingService $prices,
        private CsvProductImportService $imports,
        private ShopifyObservationStore $shopify,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(ProductManagementQuery $query): array
    {
        $org=$query->organization->value();
        $in=$query->input;
        return match($query->operation) {
            'product.get' => $this->get($org,(string)$in['product_id']),
            'product.search' => [
                'items'=>$this->products->searchProducts($org,(string)($in['q']??''),(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'stock.movement.search' => [
                'items'=>$this->products->stockMovements($org,(string)$in['variant_id'],(int)($in['limit']??30),(int)($in['offset']??0)),
                'limit'=>(int)($in['limit']??30),'offset'=>(int)($in['offset']??0),
            ],
            'stock.balance.get' => $this->products->balance($org,(string)$in['variant_id'],(string)$in['location_id']),
            'import.batch.get' => $this->imports->report($org,(string)$in['batch_id']),
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
