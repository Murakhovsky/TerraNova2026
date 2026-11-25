<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Service;

use Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort;
use Domains\ProductManagement\Application\Contract\ProductManagementStoreInterface;

final readonly class ProductCommercialReference implements ProductCommercialReferencePort
{
    public function __construct(private ProductManagementStoreInterface $products){}
    public function quoteReference(string $organizationId,string $productId,string $variantId):?array
    {
        $row=$this->products->getProduct($organizationId,$productId);
        if ($row===null||($row['organization_id']??null)!==$organizationId||($row['product_id']??null)!==$productId||($row['status']??null)!=='active') return null;
        foreach($row['variants']??[] as $v){
            if(($v['organization_id']??null)!==$organizationId||($v['product_id']??null)!==$productId||($v['variant_id']??null)!==$variantId||($v['status']??null)!=='active')continue;
            return ['product_id'=>$productId,'variant_id'=>$variantId,'name'=>(string)$row['name'],'product_type'=>(string)$row['product_type'],
                'sku'=>(string)$v['sku'],'uom'=>(string)$v['uom'],'product_revision'=>(int)$row['revision'],'variant_revision'=>(int)$v['revision']];
        }
        return null;
    }
}
