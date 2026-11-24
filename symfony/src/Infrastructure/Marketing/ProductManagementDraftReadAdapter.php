<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingProductDraftReadPort;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Module\ActiveModuleResolver;

/**
 * Anti-corruption adapter: only Product's public application reads, no foreign
 * persistence/table access. A tenant-disabled Product module cannot be bypassed.
 */
final readonly class ProductManagementDraftReadAdapter implements MarketingProductDraftReadPort
{
    public function __construct(
        private ProductManagementService $products,
        private PricingService $prices,
        private ActiveModuleResolver $modules,
    ){}

    public function inspect(string $organizationId,string $productId,?string $variantId,string $currency):?array
    {
        if(!$this->modules->isEnabled($organizationId,'product_management')){
            throw new DomainException('Product Management is not active for this tenant.');
        }
        $product=$this->products->getProduct($organizationId,$productId);
        if($product===null)return null;
        if(($product['product_id']??null)!==$productId){
            throw new DomainException('Product Management returned another Product reference.');
        }
        $variant=null;
        if($variantId!==null){
            foreach(($product['variants']??[]) as $candidate){
                if(is_array($candidate)&&($candidate['variant_id']??null)===$variantId){
                    $variant=$candidate;
                    break;
                }
            }
        }
        $price=null;
        if($variant!==null&&($variant['status']??null)==='active'){
            $priced=$this->prices->get($organizationId,$variantId,$currency);
            $price=is_string($priced['price']??null)?$priced['price']:null;
        }
        return [
            'product_ref'=>$productId,
            'variant_ref'=>$variantId,
            'currency'=>$currency,
            'product_status'=>(string)($product['status']??'unknown'),
            'variant_status'=>$variantId===null?'not_selected':(string)($variant['status']??'not_found'),
            'product_revision'=>(int)($product['revision']??0),
            'variant_revision'=>(int)($variant['revision']??0),
            'base_price_decimal'=>$price
        ];
    }
}
